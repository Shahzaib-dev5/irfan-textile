<?php

namespace App\Modules\Inventory\Services;

use App\Models\AccountingPeriod;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentSequence;
use App\Models\DocumentStatusEvent;
use App\Models\DocumentType;
use App\Models\FabricRoll;
use App\Models\FinancialYear;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentLine;
use App\Models\InventoryLayerAllocation;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
use App\Models\InventoryValueEntry;
use App\Models\Item;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\PostingAccount;
use App\Models\ProductionCostAllocation;
use App\Models\StockBalance;
use App\Models\StockCostLayer;
use App\Models\StockLot;
use App\Models\StockPosition;
use App\Models\StockReservation;
use App\Models\WarehouseLocation;
use App\Modules\Accounting\Services\SourceJournal;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Support\Decimal as D;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InventoryService
{
    public function __construct(private StockLedger $ledger, private AuditLogger $audit) {}

    private function inventoryDocument(int $id): Document
    {
        $document = Document::with('type')->lockForUpdate()->findOrFail($id);
        abort_unless($document->type->module_code === 'inventory', 404);

        return $document;
    }

    private function transaction(int $actor, string $permission, callable $callback): mixed
    {
        return DB::transaction(function () use ($actor, $permission, $callback) {
            InventoryGuard::company();
            InventoryGuard::actor($actor, $permission);

            return $callback();
        }, 3);
    }

    private function key(string $key): void
    {
        if (! Str::isUuid($key)) {
            InventoryGuard::fail('A UUID request key is required.');
        }
    }

    private function calendar(string $date): array
    {
        validator(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->validate();
        if ($date > InventoryGuard::businessDate()) {
            InventoryGuard::fail('Future business dates cannot be posted.');
        }
        $year = FinancialYear::whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->lockForUpdate()->first();
        $period = $year ? AccountingPeriod::where('financial_year_id', $year->id)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->lockForUpdate()->first() : null;
        if (! $year || ! $period || $year->is_closed || $period->status !== 'open') {
            InventoryGuard::fail('Select a date in an open financial year and accounting period.');
        }

        return [$year, $period];
    }

    private function account(string $purpose): int
    {
        $id = PostingAccount::where('purpose_code', $purpose)->value('ledger_account_id');
        if (! $id || ! LedgerAccount::whereKey($id)->where('is_active', true)->where('is_postable', true)->exists()) {
            InventoryGuard::fail('Configure an active posting account for '.$purpose.'.');
        }

        return (int) $id;
    }

    public function save(int $actor, array $input, ?int $documentId = null): Document
    {
        return $this->transaction($actor, 'inventory.manage', function () use ($actor, $input, $documentId) {
            $data = validator($input, [
                'kind' => 'required|in:adjustment,transfer', 'document_date' => 'required|date_format:Y-m-d', 'idempotency_key' => 'required|uuid',
                'external_reference' => 'nullable|string|max:100', 'lock_version' => 'sometimes|integer|min:0',
                'adjustment_reason' => 'required_if:kind,adjustment|in:opening,count,damage,write_off',
                'reference' => 'nullable|string|max:120', 'explanation' => 'required|string|min:5|max:1000',
                'related_transfer_document_id' => 'nullable|integer|exists:inventory_transfers,document_id',
                'lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array',
            ])->validate();
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            if (! $documentId && ($existing = Document::where('idempotency_key', $data['idempotency_key'])->first())) {
                $created = AuditEvent::where('document_id', $existing->id)->where('action', 'draft_created')->first();
                if (($created?->after_json['create_input_hash'] ?? null) !== $hash) {
                    InventoryGuard::fail('This request key belongs to a different draft payload.');
                }

                return $existing;
            }
            [$year,$period] = $this->calendar($data['document_date']);
            $type = DocumentType::where('code', 'inventory_'.$data['kind'])->where('is_active', true)->firstOrFail();
            $document = $documentId ? $this->inventoryDocument($documentId) : new Document;
            if ($document->exists) {
                $this->draft($document, $data['lock_version'] ?? -1);
                if ($document->document_type_id !== $type->id || $document->idempotency_key !== $data['idempotency_key']) {
                    InventoryGuard::fail('A draft cannot change document type or creation key.');
                }
                if ($this->activeReservations($document->id)->exists()) {
                    InventoryGuard::fail('Release reservations before editing this draft.');
                }
                DB::table('inventory_adjustment_lines')->where('document_id', $document->id)->delete();
                DB::table('inventory_transfer_lines')->where('document_id', $document->id)->delete();
            }
            $document->fill(['document_type_id' => $type->id, 'document_date' => $data['document_date'], 'financial_year_id' => $year->id, 'accounting_period_id' => $period->id, 'external_reference' => $data['external_reference'] ?? null]);
            if (! $document->exists) {
                $document->fill(['created_by' => $actor, 'status' => 'draft', 'idempotency_key' => $data['idempotency_key'], 'lock_version' => 0]);
            } else {
                $document->lock_version++;
            }
            $document->save();
            if ($data['kind'] === 'adjustment') {
                $this->prepareAdjustment($document, $data);
            } else {
                $this->prepareTransfer($document, $data);
            }
            $this->audit->record('documents', $document->id, $documentId ? 'draft_updated' : 'draft_created', null, ['create_input_hash' => $hash, 'document' => $document->toArray(), 'payload' => $this->payload($document)], $actor, $document->id);
            if (! $documentId) {
                $this->event($document, null, 'draft', $actor, $data['idempotency_key']);
            }

            return $document->fresh();
        });
    }

    private function prepareAdjustment(Document $doc, array $data): void
    {
        $reason = $data['adjustment_reason'];
        if ($reason === 'opening' && blank($data['reference'] ?? null)) {
            InventoryGuard::fail('Opening stock requires a source reference and evidence explanation.');
        }
        InventoryAdjustment::updateOrCreate(['document_id' => $doc->id], ['adjustment_reason' => $reason, 'reference' => $data['reference'] ?? null, 'explanation' => $data['explanation']]);
        $seen = [];
        foreach ($data['lines'] as $index => $input) {
            $position = $this->adjustmentPosition($doc, $input, $reason);
            if (isset($seen[$position->id])) {
                InventoryGuard::fail('Use one adjustment line per stock position.');
            }
            $seen[$position->id] = true;
            $position = $this->ledger->position($position->id, $doc->document_date->format('Y-m-d'));
            $expected = null;
            $count = null;
            $cutoff = null;
            if ($reason === 'count') {
                $count = D::input($input['counted_quantity'] ?? '');
                $this->checkPrecision($position, $count);
                $expected = $this->ledger->onHand($position->id);
                $qty = bcsub($count, $expected, 6);
                if (! bccomp($qty, '0', 6)) {
                    InventoryGuard::fail('A matching count has no adjustment. Record only evidenced differences.');
                }
                $cutoff = $position->movements()->max('id');
            } else {
                $qty = $this->ledger->quantity($position, $input['quantity_delta'] ?? '', true);
            }
            if ($reason === 'opening' && (bccomp($qty, '0', 6) <= 0 || $position->movements()->exists() || $position->lot->origin_document_id !== $doc->id)) {
                InventoryGuard::fail('Opening stock must be positive and belong to an unposted lot created by this opening document.');
            }
            if (in_array($reason, ['damage', 'write_off'], true) && bccomp($qty, '0', 6) >= 0) {
                InventoryGuard::fail('Damage and write-offs must issue a positive amount of existing stock, entered as a negative delta.');
            }
            $cost = bccomp($qty, '0', 6) > 0 ? D::input($input['positive_unit_cost_pkr'] ?? '', 8) : null;
            if ($cost === null && filled($input['positive_unit_cost_pkr'] ?? null)) {
                InventoryGuard::fail('An issue uses FIFO cost, not a user-entered unit cost.');
            }
            $offset = $this->account($reason === 'opening' ? 'opening_equity' : 'inventory_variance');
            InventoryAdjustmentLine::create(['document_id' => $doc->id, 'line_no' => $index + 1, 'stock_position_id' => $position->id, 'quantity_delta' => $qty, 'counted_quantity' => $count, 'expected_quantity' => $expected, 'count_cutoff_movement_id' => $cutoff, 'positive_unit_cost_pkr' => $cost, 'offset_account_id' => $offset]);
        }
    }

    private function adjustmentPosition(Document $doc, array $input, string $reason): StockPosition
    {
        if (! empty($input['stock_position_id'])) {
            return StockPosition::findOrFail($input['stock_position_id']);
        }
        $data = validator($input, [
            'item_id' => 'required|integer|exists:items,id', 'warehouse_location_id' => 'required|integer|exists:warehouse_locations,id',
            'lot_number' => 'required|string|max:50', 'external_lot_reference' => 'nullable|string|max:100',
            'quality_status' => 'required|in:pending,released,held,rejected', 'roll_number' => 'nullable|string|max:60', 'external_roll_reference' => 'nullable|string|max:100', 'width_mm' => 'nullable|decimal:0,3|gt:0|max:999999999',
        ])->validate();
        $item = Item::findOrFail($data['item_id']);
        $lot = StockLot::where('lot_number', $data['lot_number'])->first();
        if (! $lot) {
            if ($reason !== 'opening') {
                InventoryGuard::fail('New lots require an evidenced opening or a future receipt workflow.');
            }
            $lot = StockLot::create(['item_id' => $item->id, 'lot_number' => $data['lot_number'], 'origin_document_id' => $doc->id, 'quality_status' => $data['quality_status'], 'external_lot_reference' => $data['external_lot_reference'] ?? null]);
        }
        if ($lot->item_id !== $item->id) {
            InventoryGuard::fail('The lot number already belongs to another item.');
        }
        if ($lot->origin_document_id !== $doc->id && ! in_array($lot->origin->status, ['posted', 'reversed'], true)) {
            InventoryGuard::fail('The lot originates from another unposted document.');
        }
        $roll = null;
        if ($item->track_rolls) {
            if (blank($data['roll_number'] ?? null)) {
                InventoryGuard::fail('This item requires a uniquely identified fabric roll.');
            }
            $roll = FabricRoll::firstOrCreate(['roll_number' => $data['roll_number']], ['stock_lot_id' => $lot->id, 'external_roll_reference' => $data['external_roll_reference'] ?? null, 'width_mm' => $data['width_mm'] ?? null]);
            if ($roll->stock_lot_id !== $lot->id) {
                InventoryGuard::fail('The roll number already belongs to another lot.');
            }
        } elseif (filled($data['roll_number'] ?? null)) {
            InventoryGuard::fail('This item does not use roll tracking.');
        }

        return $this->makePosition($lot->id, $data['warehouse_location_id'], $roll?->id);
    }

    private function makePosition(int $lot, int $location, ?int $roll): StockPosition
    {
        $position = StockPosition::firstOrCreate(['stock_lot_id' => $lot, 'warehouse_location_id' => $location, 'fabric_roll_id' => $roll]);
        StockBalance::firstOrCreate(['stock_position_id' => $position->id], ['on_hand_quantity' => '0', 'reserved_quantity' => '0']);

        return $position;
    }

    private function prepareTransfer(Document $doc, array $data): void
    {
        $related = $data['related_transfer_document_id'] ?? null;
        if ($related && ($related === $doc->id || Document::findOrFail($related)->status !== 'posted')) {
            InventoryGuard::fail('A transit leg must refer to a different, already posted transfer.');
        }
        InventoryTransfer::updateOrCreate(['document_id' => $doc->id], ['related_transfer_document_id' => $related, 'remarks' => mb_substr($data['explanation'], 0, 500)]);
        $lineNo = 0;
        $used = [];
        $sourceUsed = [];
        foreach ($data['lines'] as $input) {
            validator($input, ['source_stock_position_id' => 'required|integer|exists:stock_positions,id', 'destination_location_id' => 'required|integer|exists:warehouse_locations,id', 'quantity' => 'required'])->validate();
            $source = $this->ledger->position($input['source_stock_position_id'], $doc->document_date->format('Y-m-d'));
            $target = WarehouseLocation::findOrFail($input['destination_location_id']);
            if ($source->lot->quality_status !== 'released' && $target->location_type !== 'quarantine') {
                InventoryGuard::fail('Unreleased stock can only be relocated into quarantine.');
            }
            if ($source->warehouse_location_id === $target->id) {
                InventoryGuard::fail('Choose a different destination location.');
            }
            $qty = $this->ledger->quantity($source, $input['quantity']);
            if (bccomp($qty, '0', 6) <= 0) {
                InventoryGuard::fail('Transfer quantity must be positive.');
            }
            $sourceUsed[$source->id] = bcadd($sourceUsed[$source->id] ?? '0', $qty, 6);
            if (bccomp($sourceUsed[$source->id], $this->ledger->available($source->id, $doc->id), 6) > 0) {
                InventoryGuard::fail('Transfer exceeds available stock.');
            }
            if ($source->fabric_roll_id && bccomp($qty, $this->ledger->onHand($source->id), 6)) {
                InventoryGuard::fail('Transfer the entire tracked roll.');
            }
            $destination = $this->makePosition($source->stock_lot_id, $target->id, $source->fabric_roll_id);
            $this->ledger->position($destination->id, $doc->document_date->format('Y-m-d'));
            foreach ($this->ledger->plan($source->id, $sourceUsed[$source->id], $doc->document_date->format('Y-m-d')) as [$layer,$total]) {
                $take = bcsub($total, $used[$layer->id] ?? '0', 6);
                $used[$layer->id] = $total;
                if (bccomp($take, '0', 6) <= 0) {
                    continue;
                }
                InventoryTransferLine::create(['document_id' => $doc->id, 'line_no' => ++$lineNo, 'source_stock_position_id' => $source->id, 'destination_stock_position_id' => $destination->id, 'quantity' => $take, 'source_stock_cost_layer_id' => $layer->id]);
            }
        }
    }

    private function checkPrecision(StockPosition $position, string $quantity): void
    {
        if (bccomp($quantity, bcadd($quantity, '0', $position->lot->item->baseUnit->decimal_places), 6)) {
            InventoryGuard::fail('Count does not match base-unit precision.');
        }
    }

    private function draft(Document $document, int $version): void
    {
        if ($document->status !== 'draft' || $document->lock_version !== $version) {
            InventoryGuard::fail('The document changed or is no longer a draft. Reload before continuing.');
        }
    }

    private function activeReservations(int $doc): Builder
    {
        return StockReservation::where('document_id', $doc)->whereRaw('quantity>fulfilled_quantity+released_quantity');
    }

    public function post(int $actor, int $id, string $key, int $version): Document
    {
        $this->key($key);

        return $this->transaction($actor, 'inventory.post', function () use ($actor, $id, $key, $version) {
            $doc = $this->inventoryDocument($id);
            $hash = hash('sha256', json_encode($this->payload($doc), JSON_THROW_ON_ERROR), true);
            if (in_array($doc->status, ['posted', 'reversed'], true)) {
                if ($doc->posting_key === $key && hash_equals($doc->getRawOriginal('payload_hash'), $hash)) {
                    return $doc;
                }
                InventoryGuard::fail('This document is already posted under another request or payload.');
            }
            $this->draft($doc, $version);
            $this->postingCalendar($doc);
            if (Document::where('posting_key', $key)->exists()) {
                InventoryGuard::fail('This posting request key has already been used.');
            }
            if ($doc->transfer) {
                $positions = $this->postTransfer($doc, $actor);
            } elseif ($doc->adjustment) {
                $positions = $this->postAdjustment($doc);
            } else {
                InventoryGuard::fail('The inventory document has no typed header.');
            }
            $this->ledger->validateRolls($positions);
            foreach (array_unique($positions) as $position) {
                $this->ledger->refresh($position);
            }
            $this->finalize($doc, $actor, $key, $hash);

            return $doc->fresh();
        });
    }

    private function postingCalendar(Document $doc): void
    {
        [$year,$period] = $this->calendar($doc->document_date->format('Y-m-d'));
        if ($year->id !== $doc->financial_year_id || $period->id !== $doc->accounting_period_id) {
            InventoryGuard::fail('The document calendar no longer matches its date.');
        }
        if (! $doc->type->is_active) {
            InventoryGuard::fail('This document type is inactive.');
        }
    }

    private function postAdjustment(Document $doc): array
    {
        $positions = [];
        $journal = [];
        $reason = $doc->adjustment->adjustment_reason;
        $inventory = $this->account('inventory');
        if ($doc->adjustment->lines->isEmpty()) {
            InventoryGuard::fail('An adjustment requires detail lines.');
        }
        foreach ($doc->adjustment->lines as $line) {
            $position = $this->ledger->position($line->stock_position_id, $doc->document_date->format('Y-m-d'));
            $positions[] = $position->id;
            $qty = $this->ledger->quantity($position, $line->quantity_delta, true);
            if (in_array($reason, ['damage', 'write_off'], true) && bccomp($qty, '0', 6) >= 0) {
                InventoryGuard::fail('Damage and write-off postings must reduce stock.');
            }
            if ($reason === 'count' && ($line->counted_quantity === null || $line->expected_quantity === null || bccomp(bcsub($line->counted_quantity, $line->expected_quantity, 6), $qty, 6))) {
                InventoryGuard::fail('The physical count does not match its frozen adjustment.');
            }
            $offset = $this->account($reason === 'opening' ? 'opening_equity' : 'inventory_variance');
            if ($offset !== $line->offset_account_id || $offset === $inventory) {
                InventoryGuard::fail('Posting account configuration changed. Refresh the draft.');
            }
            if ($reason === 'count' && ((int) $position->movements()->max('id') !== (int) $line->count_cutoff_movement_id || bccomp($this->ledger->onHand($position->id), $line->expected_quantity, 6))) {
                InventoryGuard::fail('This count snapshot is stale. Recount and edit the draft.');
            }
            if ($reason === 'opening' && ($position->movements()->exists() || $position->lot->origin_document_id !== $doc->id || bccomp($qty, '0', 6) <= 0)) {
                InventoryGuard::fail('Opening stock has already been introduced or belongs to a different origin.');
            }
            if (bccomp($qty, '0', 6) < 0 && bccomp(D::negative($qty), $this->ledger->available($position->id, $doc->id), 6) > 0) {
                InventoryGuard::fail('Issue exceeds unreserved stock.');
            }
            $movement = $this->ledger->movement($doc, $position->id, $qty, $reason === 'write_off' ? 'damage' : $reason);
            $amount = '0.0000';
            if (bccomp($qty, '0', 6) > 0) {
                if ($line->positive_unit_cost_pkr === null) {
                    InventoryGuard::fail('A gain requires its evidenced unit cost.');
                }
                $amount = D::round(bcmul($qty, $line->positive_unit_cost_pkr, 14));
                $this->ledger->receipt($doc, $movement, $qty, $amount);
            } else {
                $needed = D::negative($qty);

                foreach ($this->ledger->plan($position->id, $needed, $doc->document_date->format('Y-m-d')) as [$layer,$take]) {
                    [$allocation,$cost] = $this->ledger->issue($doc, $movement, $layer, $take);
                    $this->ledger->link($doc, $allocation, $cost, null, $offset);
                    $amount = bcsub($amount, $cost, 4);
                }
            }
            $line->update(['stock_movement_id' => $movement->id]);
            $journal[$inventory] = bcadd($journal[$inventory] ?? '0', $amount, 4);
            $journal[$offset] = bcsub($journal[$offset] ?? '0', $amount, 4);
        }
        $this->journal($doc, $journal, $reason === 'opening' ? 'opening' : 'operational');

        return $positions;
    }

    private function postTransfer(Document $doc, int $actor): array
    {
        $positions = [];
        $required = [];
        $validated = [];
        if ($doc->transfer->lines->isEmpty()) {
            InventoryGuard::fail('A transfer requires detail lines.');
        }
        foreach ($doc->transfer->lines as $line) {
            $required[$line->source_stock_position_id] = bcadd($required[$line->source_stock_position_id] ?? '0', $line->quantity, 6);
        }
        foreach ($required as $id => $qty) {
            $source = $this->ledger->position($id, $doc->document_date->format('Y-m-d'));
            if (bccomp($qty, $this->ledger->available($id, $doc->id), 6) > 0) {
                InventoryGuard::fail('Transfer exceeds available stock.');
            }
            if ($source->fabric_roll_id && bccomp($qty, $this->ledger->onHand($id), 6)) {
                InventoryGuard::fail('A tracked roll must transfer in full.');
            }
        }
        foreach ($required as $position => $quantity) {
            if ($doc->transfer->lines->contains(fn ($line) => $line->source_stock_position_id === $position && bccomp($line->quantity, '0', 6) <= 0)) {
                InventoryGuard::fail('Transfer quantities must be positive.');
            }
            $expected = [];
            $actual = [];
            foreach ($this->ledger->plan($position, $quantity, $doc->document_date->format('Y-m-d')) as [$layer,$take]) {
                $expected[$layer->id] = $take;
            }
            foreach ($doc->transfer->lines->where('source_stock_position_id', $position) as $line) {
                $actual[$line->source_stock_cost_layer_id] = bcadd($actual[$line->source_stock_cost_layer_id] ?? '0', $line->quantity, 6);
            }
            ksort($expected);
            ksort($actual);
            if ($actual !== $expected) {
                InventoryGuard::fail('FIFO plan changed. Edit the draft before posting.');
            }
        }
        foreach ($doc->transfer->lines as $line) {
            foreach ([$line->source_stock_position_id, $line->destination_stock_position_id] as $positionId) {
                $validated[$positionId] = $this->ledger->position($positionId, $doc->document_date->format('Y-m-d'));
            }
        }
        // Fulfil our commitments before the balance projection is refreshed.
        foreach ($this->activeReservations($doc->id)->lockForUpdate()->get() as $reservation) {
            if ($reservation->expires_at && $reservation->expires_at->isPast()) {
                InventoryGuard::fail('A reservation expired. Release and reserve again before posting.');
            }
            $before = $reservation->toArray();
            $reservation->update(['fulfilled_quantity' => bcsub($reservation->quantity, $reservation->released_quantity, 6)]);
            $this->audit->record('stock_reservations', $reservation->id, 'fulfilled', $before, $reservation->fresh()->toArray(), $actor, $doc->id);
        }
        foreach ($doc->transfer->lines as $line) {
            $source = $validated[$line->source_stock_position_id];
            $target = $validated[$line->destination_stock_position_id];
            if ($source->stock_lot_id !== $target->stock_lot_id || $source->fabric_roll_id !== $target->fabric_roll_id || $source->id === $target->id) {
                InventoryGuard::fail('A transfer must retain its lot and roll identity.');
            }
            if ($source->lot->quality_status !== 'released' && $target->location->location_type !== 'quarantine') {
                InventoryGuard::fail('Unreleased stock can only move into quarantine.');
            }
            $layer = StockCostLayer::lockForUpdate()->findOrFail($line->source_stock_cost_layer_id);
            if ($layer->stock_position_id !== $source->id || $layer->fifo_date->gt($doc->document_date)) {
                InventoryGuard::fail('The selected FIFO source is invalid.');
            }
            $out = $this->ledger->movement($doc, $source->id, D::negative($line->quantity), 'transfer');
            [$allocation,$cost] = $this->ledger->issue($doc, $out, $layer, $line->quantity);
            $in = $this->ledger->movement($doc, $target->id, $line->quantity, 'transfer');
            $destinationLayer = $this->ledger->receipt($doc, $in, $line->quantity, $cost, $layer->fifo_date->format('Y-m-d'));
            $this->ledger->link($doc, $allocation, $cost, $destinationLayer->id, null);
            $line->update(['source_stock_movement_id' => $out->id, 'destination_stock_movement_id' => $in->id]);
            $positions[] = $source->id;
            $positions[] = $target->id;
        }

        return $positions;
    }

    private function journal(Document $doc, array $amounts, string $kind): void
    {
        app(SourceJournal::class)->post($doc, $amounts, $kind);
    }

    private function payload(Document $doc): array
    {
        $doc->unsetRelation('adjustment')->unsetRelation('transfer');
        $header = $doc->adjustment ?? $doc->transfer;
        $fields = $doc->adjustment ? ['stock_position_id', 'quantity_delta', 'counted_quantity', 'expected_quantity', 'count_cutoff_movement_id', 'positive_unit_cost_pkr', 'offset_account_id'] : ['source_stock_position_id', 'destination_stock_position_id', 'quantity', 'source_stock_cost_layer_id'];

        return ['type' => $doc->document_type_id, 'date' => $doc->document_date->format('Y-m-d'), 'year' => $doc->financial_year_id, 'period' => $doc->accounting_period_id, 'reference' => $doc->external_reference, 'header' => $header?->only($doc->adjustment ? ['adjustment_reason', 'reference', 'explanation'] : ['related_transfer_document_id', 'remarks']), 'lines' => $header?->lines->map(fn ($line) => $line->only($fields))->all()];
    }

    private function finalize(Document $doc, int $actor, string $key, string $hash): void
    {
        $type = $doc->type;
        $sequence = DocumentSequence::firstOrCreate(['document_type_id' => $type->id, 'financial_year_id' => $doc->financial_year_id], ['next_value' => 1]);
        $sequence = DocumentSequence::lockForUpdate()->findOrFail($sequence->id);
        $number = $type->prefix.'-'.$doc->financial_year_id.'-'.str_pad((string) $sequence->next_value, $type->numbering_width, '0', STR_PAD_LEFT);
        if (strlen($number) > 64) {
            InventoryGuard::fail('Document numbering capacity exceeded.');
        }
        $sequence->update(['next_value' => $sequence->next_value + 1]);
        $doc->update(['number' => $number, 'status' => 'posted', 'posting_key' => $key, 'payload_hash' => $hash, 'posted_by' => $actor, 'posted_at' => now(), 'lock_version' => $doc->lock_version + 1]);
        $this->event($doc, 'draft', 'posted', $actor, $key);
        $this->audit->record('documents', $doc->id, 'posted', null, ['number' => $number, 'payload_hash' => bin2hex($hash)], $actor, $doc->id);
    }

    private function event(Document $doc, ?string $from, string $to, int $actor, string $key, ?string $reason = null): void
    {
        DocumentStatusEvent::create(['document_id' => $doc->id, 'event_no' => 1 + (int) $doc->events()->max('event_no'), 'from_status' => $from, 'to_status' => $to, 'actor_id' => $actor, 'request_key' => $key, 'reason' => $reason]);
    }

    public function cancel(int $actor, int $id, int $version, string $reason): void
    {
        $this->reason($reason);
        $this->transaction($actor, 'inventory.manage', function () use ($actor, $id, $version, $reason) {
            $doc = $this->inventoryDocument($id);
            $this->draft($doc, $version);
            $this->releaseInternal($doc, $actor, 'Document cancellation: '.$reason);
            $doc->update(['status' => 'cancelled', 'reason' => $reason, 'lock_version' => $doc->lock_version + 1]);
            $this->event($doc, 'draft', 'cancelled', $actor, (string) Str::uuid(), $reason);
            $this->audit->record('documents', $id, 'cancelled', null, ['reason' => $reason], $actor, $id);
        });
    }

    private function reason(string $reason): void
    {
        validator(['reason' => $reason], ['reason' => 'required|string|min:5|max:1000'])->validate();
    }

    public function reserve(int $actor, int $id, int $version, ?string $expiresAt = null): void
    {
        $this->transaction($actor, 'inventory.reserve', function () use ($actor, $id, $version, $expiresAt) {
            $doc = $this->inventoryDocument($id);
            $this->draft($doc, $version);
            $this->postingCalendar($doc);
            if (! $doc->transfer) {
                InventoryGuard::fail('Only draft transfer documents can reserve inventory in this phase.');
            }
            if ($expiresAt) {
                validator(['expires_at' => $expiresAt], ['expires_at' => 'required|date|after:now'])->validate();
            }
            if ($this->activeReservations($id)->exists()) {
                InventoryGuard::fail('This transfer already has reservations.');
            }
            $required = [];
            foreach ($doc->transfer->lines as $line) {
                $required[$line->source_stock_position_id] = bcadd($required[$line->source_stock_position_id] ?? '0', $line->quantity, 6);
            }
            ksort($required);
            $seq = 1 + (int) StockReservation::where('document_id', $id)->max('sequence');
            foreach ($required as $position => $qty) {
                $this->ledger->position($position, $doc->document_date->format('Y-m-d'), true);
                if (bccomp($qty, $this->ledger->available($position), 6) > 0) {
                    InventoryGuard::fail('Insufficient unreserved stock.');
                }
                $r = StockReservation::create(['document_id' => $id, 'sequence' => $seq++, 'stock_position_id' => $position, 'quantity' => $qty, 'expires_at' => $expiresAt]);
                $this->ledger->refresh($position);
                $this->audit->record('stock_reservations', $r->id, 'reserved', null, $r->toArray(), $actor, $id);
            }
            $doc->increment('lock_version');
        });
    }

    public function release(int $actor, int $id, int $version, string $reason): void
    {
        $this->reason($reason);
        $this->transaction($actor, 'inventory.reserve', function () use ($actor, $id, $version, $reason) {
            $doc = $this->inventoryDocument($id);
            $this->draft($doc, $version);
            $this->releaseInternal($doc, $actor, $reason);
            $doc->increment('lock_version');
        });
    }

    private function releaseInternal(Document $doc, int $actor, string $reason): void
    {
        foreach ($this->activeReservations($doc->id)->orderBy('stock_position_id')->lockForUpdate()->get() as $reservation) {
            $this->ledger->checkProjection($reservation->stock_position_id);
            $before = $reservation->toArray();
            $reservation->update(['released_quantity' => bcsub($reservation->quantity, $reservation->fulfilled_quantity, 6)]);
            $this->ledger->refresh($reservation->stock_position_id);
            $this->audit->record('stock_reservations', $reservation->id, 'released', $before, ['reservation' => $reservation->fresh()->toArray(), 'reason' => $reason], $actor, $doc->id);
        }
    }

    public function quality(int $actor, int $lotId, string $status, string $reason): void
    {
        $this->reason($reason);
        validator(['status' => $status], ['status' => 'required|in:pending,released,held,rejected'])->validate();
        $this->transaction($actor, 'inventory.manage', function () use ($actor, $lotId, $status, $reason) {
            $lot = StockLot::lockForUpdate()->findOrFail($lotId);
            if ($status !== 'released' && StockReservation::whereIn('stock_position_id', $lot->positions()->pluck('id'))->whereRaw('quantity>fulfilled_quantity+released_quantity')->exists()) {
                InventoryGuard::fail('Release outstanding reservations before holding or rejecting a lot.');
            }
            $before = $lot->toArray();
            $lot->update(['quality_status' => $status]);
            $this->audit->record('stock_lots', $lotId, 'quality_changed', $before, ['quality_status' => $status, 'reason' => $reason], $actor);
        });
    }

    public function reverse(int $actor, int $id, string $date, string $reason, string $key): Document
    {
        $this->reason($reason);
        $this->key($key);

        return $this->transaction($actor, 'inventory.reverse', function () use ($actor, $id, $date, $reason, $key) {
            $original = $this->inventoryDocument($id);
            if (Schema::hasTable('production_cost_allocations') && ProductionCostAllocation::whereIn('source_journal_line_id', JournalLine::where('journal_entry_id', $id)->pluck('id'))->whereHas('report.document', fn ($q) => $q->where('status', 'posted'))->exists()) {
                InventoryGuard::fail('Actual source costs already absorbed by Production cannot be reversed. Unwind their dependent reports first.');
            }

            if ($existing = Document::where('reversal_of_document_id', $id)->first()) {
                if ($existing->posting_key === $key && $existing->reason === $reason && $existing->document_date->format('Y-m-d') === $date) {
                    return $existing;
                }
                InventoryGuard::fail('This document already has a reversal.');
            }
            if ($original->status !== 'posted' || $original->reversal_of_document_id) {
                InventoryGuard::fail('Only an original posted document can be reversed.');
            }
            [$year,$period] = $this->calendar($date);
            if ($date < $original->document_date->format('Y-m-d')) {
                InventoryGuard::fail('A reversal cannot predate its original posting.');
            }
            if (Document::where('posting_key', $key)->exists()) {
                InventoryGuard::fail('This posting key is already used.');
            }
            $doc = Document::create(['document_type_id' => $original->document_type_id, 'document_date' => $date, 'financial_year_id' => $year->id, 'accounting_period_id' => $period->id, 'created_by' => $actor, 'status' => 'draft', 'idempotency_key' => (string) Str::uuid(), 'reversal_of_document_id' => $id, 'reason' => $reason]);
            $this->event($doc, null, 'draft', $actor, $doc->idempotency_key, $reason);
            if ($original->adjustment) {
                $header = $original->adjustment;
                InventoryAdjustment::create(['document_id' => $doc->id, 'adjustment_reason' => $header->adjustment_reason, 'reference' => $original->number, 'explanation' => $reason]);
            } else {
                InventoryTransfer::create(['document_id' => $doc->id, 'related_transfer_document_id' => $id, 'remarks' => $reason]);
            }
            $positions = $original->movements()->orderBy('stock_position_id')->pluck('stock_position_id')->unique()->all();
            foreach ($positions as $position) {
                $this->ledger->position($position, $date);
            }
            $movements = [];
            $allocations = [];
            // Reverse in descending movement order so destination layers are checked before source restoration.
            foreach ($original->movements()->orderByDesc('sequence')->get() as $movement) {
                $inverse = $this->ledger->movement($doc, $movement->stock_position_id, D::negative($movement->quantity_delta), 'reversal', $movement->id);
                $movements[$movement->id] = $inverse;
                foreach ($movement->allocations as $allocation) {
                    $layer = StockCostLayer::lockForUpdate()->findOrFail($allocation->stock_cost_layer_id);
                    $allocations[$allocation->id] = $this->ledger->allocate($inverse, $layer, D::negative($allocation->quantity_delta));
                }
            }
            foreach (InventoryValueEntry::where('document_id', $id)->orderBy('sequence')->get() as $value) {
                $allocation = $allocations[$value->inventory_layer_allocation_id] ?? null;
                if (! $allocation) {
                    InventoryGuard::fail('Original cost entry has no reversible quantity allocation.');
                }
                $this->ledger->value($doc, StockCostLayer::findOrFail($value->stock_cost_layer_id), $allocation, D::negative($value->amount_pkr, 4), 'reversal', $value->id);
            }
            if ($original->adjustment) {
                foreach ($original->adjustment->lines as $line) {
                    $rate = null;
                    if (bccomp($line->quantity_delta, '0', 6) < 0) {
                        $originalAllocations = InventoryLayerAllocation::where('stock_movement_id', $line->stock_movement_id)->pluck('id');
                        $originalValue = (string) InventoryValueEntry::where('document_id', $id)->whereIn('inventory_layer_allocation_id', $originalAllocations)->sum('amount_pkr');
                        $rate = D::round(bcdiv($originalValue, $line->quantity_delta, 16), 8);
                    }
                    InventoryAdjustmentLine::create(['document_id' => $doc->id, 'line_no' => $line->line_no, 'stock_position_id' => $line->stock_position_id, 'quantity_delta' => D::negative($line->quantity_delta), 'positive_unit_cost_pkr' => $rate, 'offset_account_id' => $line->offset_account_id, 'stock_movement_id' => $movements[$line->stock_movement_id]->id]);
                }
            } else {
                foreach ($original->transfer->lines as $line) {
                    $sourceLayer = StockCostLayer::where('receipt_stock_movement_id', $line->destination_stock_movement_id)->firstOrFail();
                    InventoryTransferLine::create(['document_id' => $doc->id, 'line_no' => $line->line_no, 'source_stock_position_id' => $line->destination_stock_position_id, 'destination_stock_position_id' => $line->source_stock_position_id, 'quantity' => $line->quantity, 'source_stock_cost_layer_id' => $sourceLayer->id, 'source_stock_movement_id' => $movements[$line->destination_stock_movement_id]->id, 'destination_stock_movement_id' => $movements[$line->source_stock_movement_id]->id]);
                }
            }
            $journal = [];
            foreach ($original->journal?->lines ?? [] as $line) {
                $journal[$line->ledger_account_id] = bcadd($journal[$line->ledger_account_id] ?? '0', bcsub($line->credit, $line->debit, 4), 4);
            }
            $this->journal($doc, $journal, 'reversal');
            $this->ledger->validateRolls($positions);
            foreach ($positions as $position) {
                $this->ledger->refresh($position);
            }
            $this->finalize($doc, $actor, $key, hash('sha256', json_encode($this->payload($doc), JSON_THROW_ON_ERROR), true));
            $original->update(['status' => 'reversed', 'reason' => $reason, 'reversed_by' => $actor, 'reversed_at' => now(), 'lock_version' => $original->lock_version + 1]);
            $this->event($original, 'posted', 'reversed', $actor, $key, $reason);
            $this->audit->record('documents', $id, 'reversed', null, ['reversal_document_id' => $doc->id, 'reason' => $reason], $actor, $id);

            return $doc->fresh();
        });
    }
}

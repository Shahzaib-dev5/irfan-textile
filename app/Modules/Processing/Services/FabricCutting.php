<?php

namespace App\Modules\Processing\Services;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Models\StockPosition;
use App\Modules\Accounting\Services\SourceJournal;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as Guard;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Support\Decimal as D;
use App\Modules\Purchasing\Services\PurchaseDocuments;
use Illuminate\Support\Facades\DB;

/** Invoked only inside the Fabric Out company-locked transaction. */
class FabricCutting
{
    public function cut(int $actor, array $d, string $key): StockPosition
    {
        Guard::actor($actor, 'processing.dispatches.post');
        if (! empty($d['manufacturing_batch_document_id'])) {
            Guard::fail('Use the batch production report for cutting linked manufacturing WIP.');
        }
        $ledger = app(StockLedger::class);
        $documents = app(PurchaseDocuments::class);
        $source = $ledger->position($d['source_stock_position_id'], $d['document_date'], true);
        if ($source->location->location_type !== 'internal') {
            Guard::fail('Pre-dispatch cutting requires your warehouse stock.');
        }
        $quantity = $ledger->quantity($source, $d['quantity']);
        if (bccomp($quantity, $ledger->available($source->id), 6) > 0) {
            Guard::fail('Insufficient unreserved stock for cutting.');
        }
        $out = D::input($d['cut_piece_quantity']);
        $retained = D::input($d['cut_retained_meters']);
        $normal = D::input($d['cut_normal_loss'] ?? '0');
        $abnormal = D::input($d['cut_abnormal_loss'] ?? '0');
        if (bccomp($out, '0', 6) <= 0 || bccomp($out, bcadd($out, '0', 0), 6) || bccomp($retained, '0', 6) <= 0 || bccomp($quantity, bcadd($retained, bcadd($normal, $abnormal, 6), 6), 6)) {
            Guard::fail('Cutting requires whole pieces and consumed meters = retained meters + normal loss + abnormal loss.');
        }
        foreach ([$retained, $normal, $abnormal] as $q) {
            if (bccomp($q, bcadd($q, '0', $source->lot->item->baseUnit->decimal_places), 6)) {
                Guard::fail('Cutting meters exceed the source unit precision.');
            }
        }
        if ($source->roll && bccomp($quantity, $ledger->onHand($source->id), 6)) {
            Guard::fail('Cut the full tracked roll; partial-roll splitting is not supported.');
        }
        validator($d, ['cut_evidence' => 'required|string|min:10|max:1000'])->validate();
        $item = app(FabricMeasurement::class)->pieces($source->lot->item, $actor);
        [$year, $period] = $documents->calendar($d['document_date']);
        $doc = Document::create(['document_type_id' => DocumentType::where('code', 'fabric_cutting')->firstOrFail()->id, 'document_date' => $d['document_date'], 'financial_year_id' => $year->id, 'accounting_period_id' => $period->id, 'created_by' => $actor, 'status' => 'draft', 'idempotency_key' => $key]);
        $lot = StockLot::create(['item_id' => $item->id, 'lot_number' => 'CUT-'.str_replace('-', '', $key), 'origin_document_id' => $doc->id, 'quality_status' => 'released']);
        $target = StockPosition::create(['stock_lot_id' => $lot->id, 'warehouse_location_id' => $source->warehouse_location_id, 'fabric_roll_id' => null]);
        StockBalance::create(['stock_position_id' => $target->id, 'on_hand_quantity' => 0, 'reserved_quantity' => 0]);
        $parts = [];
        $total = $loss = '0.0000';
        $fifo = null;
        $fraction = bcdiv($abnormal, $quantity, 10);
        if (bccomp($abnormal, '0', 6) > 0 && bccomp($fraction, '0', 10) === 0) {
            Guard::fail('Loss share is too small for cost lineage precision.');
        }
        foreach ($ledger->plan($source->id, $quantity, $d['document_date']) as [$layer, $take]) {
            $movement = $ledger->movement($doc, $source->id, D::negative($take), 'production');
            [$allocation, $cost] = $ledger->issue($doc, $movement, $layer, $take);
            $expense = D::round(bcmul($cost, $fraction, 18));
            $total = bcadd($total, $cost, 4);
            $loss = bcadd($loss, $expense, 4);
            $parts[] = [$allocation, $cost, $expense];
            $date = $layer->fifo_date->format('Y-m-d');
            $fifo = $fifo === null || $date < $fifo ? $date : $fifo;
        }
        $movement = $ledger->movement($doc, $target->id, $out, 'production');
        $outputLayer = $ledger->receipt($doc, $movement, $out, bcsub($total, $loss, 4), $fifo);
        foreach ($parts as [$allocation, $cost, $expense]) {
            $ledger->link($doc, $allocation, bcsub($cost, $expense, 4), $outputLayer->id, null, bcsub('1', $fraction, 10));
            if (bccomp($abnormal, '0', 6) > 0) {
                $ledger->link($doc, $allocation, $expense, null, $documents->account('abnormal_processing_loss'), $fraction);
            }
        }
        if (bccomp($loss, '0', 4) > 0) {
            app(SourceJournal::class)->post($doc, [$documents->account('inventory') => D::negative($loss, 4), $documents->account('abnormal_processing_loss') => $loss]);
        }
        DB::table('fabric_cuttings')->insert(['document_id' => $doc->id, 'source_stock_position_id' => $source->id, 'output_stock_position_id' => $target->id, 'source_quantity' => $quantity, 'output_quantity' => $out, 'retained_meters' => $retained, 'normal_loss' => $normal, 'abnormal_loss' => $abnormal, 'input_cost_pkr' => $total, 'abnormal_cost_pkr' => $loss, 'evidence' => $d['cut_evidence']]);
        $ledger->refresh($source->id);
        $ledger->refresh($target->id);
        $ledger->validateRolls([$source->id]);
        app(AuditLogger::class)->record('documents', $doc->id, 'fabric_cutting_confirmed', null, $d, $actor, $doc->id);
        $documents->finish($doc, $actor, $key, hash('sha256', json_encode($d, JSON_THROW_ON_ERROR), true));

        return $target;
    }
}

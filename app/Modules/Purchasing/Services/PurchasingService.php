<?php

namespace App\Modules\Purchasing\Services;

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\FabricRoll;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryCostLink;
use App\Models\InventoryLayerAllocation;
use App\Models\Item;
use App\Models\PartyRole;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseInvoiceReceiptMatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Models\StockPosition;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Accounting\Services\SourceJournal;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as Guard;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Support\Decimal as D;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class PurchasingService
{
    public const HEADERS = ['order' => PurchaseOrder::class, 'receipt' => GoodsReceipt::class, 'return' => PurchaseReturn::class, 'bill' => PurchaseInvoice::class];

    public const TYPES = ['order' => 'purchase_order', 'receipt' => 'goods_receipt', 'return' => 'purchase_return', 'bill' => 'purchase_invoice'];

    public const PERMISSIONS = ['order' => 'purchasing.orders.approve', 'receipt' => 'purchasing.receipts.post', 'return' => 'purchasing.returns.post', 'bill' => 'purchasing.bills.post'];

    public function __construct(private StockLedger $stock, private PurchaseDocuments $documents, private SourceJournal $journals, private AuditLogger $audit) {}

    private function transaction(int $actor, string $permission, callable $callback): mixed
    {
        return DB::transaction(function () use ($actor, $permission, $callback) {
            Guard::company();
            Guard::actor($actor, $permission);

            return $callback();
        }, 3);
    }

    public function kind(Document $doc): string
    {
        $kind = array_search($doc->type->code, self::TYPES, true);
        abort_unless($kind !== false && $doc->type->module_code === 'purchasing', 404);

        return $kind;
    }

    public function header(Document $doc): mixed
    {
        $class = self::HEADERS[$this->kind($doc)];

        return $class::with('lines')->findOrFail($doc->id);
    }

    public function workflow(Document $doc): string
    {
        if ($doc->status !== 'draft') {
            return $doc->status;
        }
        $event = AuditEvent::where('document_id', $doc->id)->whereIn('action', ['order_submitted', 'order_returned'])->latest('id')->first();

        return $event?->action === 'order_submitted' ? 'submitted' : 'draft';
    }

    public function closed(int $order): bool
    {
        return AuditEvent::where('document_id', $order)->where('action', 'order_closed')->exists();
    }

    private function supplier(int $id): PartyRole
    {
        $role = PartyRole::with(['party', 'supplierProfile', 'type'])->lockForUpdate()->findOrFail($id);
        if (! $role->is_active || ! $role->party->is_active || $role->type->code !== 'supplier' || ! $role->type->is_active || ! $role->supplierProfile) {
            Guard::fail('Choose an active shared Supplier role and profile.');
        }

        return $role;
    }

    private function warehouse(int $id): Warehouse
    {
        $warehouse = Warehouse::findOrFail($id);
        if (! $warehouse->is_active) {
            Guard::fail('Choose an active warehouse.');
        }

        return $warehouse;
    }

    private function draft(Document $doc, int $version): void
    {
        if ($doc->status !== 'draft' || $doc->lock_version !== $version) {
            Guard::fail('The draft changed or is no longer editable. Reload it.');
        }
    }

    private function money(string $value): string
    {
        if (bccomp($value, '0', 4) < 0 || bccomp($value, '10000000000000000', 4) >= 0) {
            Guard::fail('Amount is outside supported money precision.');
        }

        return bcadd($value, '0', 4);
    }

    private function snapshot(Item $item, int $unitId, string $qty, ?string $factor = null): array
    {
        $qty = D::input($qty);
        $unit = Unit::findOrFail($unitId);
        if (! $unit->is_active || $unit->dimension !== $item->baseUnit->dimension || bccomp($qty, '0', 6) <= 0 || bccomp($qty, bcadd($qty, '0', $unit->decimal_places), 6)) {
            Guard::fail('Quantity/unit is incompatible with this item.');
        }
        if ($factor === null) {
            $factor = $unitId === $item->base_unit_id ? '1' : $item->conversions()->where('unit_id', $unitId)->value('factor_to_base');
            if (! $factor) {
                Guard::fail('Configure the item-specific unit conversion first.');
            }
            if (bccomp($factor, bcadd($factor, '0', 8), 10)) {
                Guard::fail('This conversion exceeds the approved document snapshot precision of eight decimals.');
            }
        }
        $base = bcmul($qty, $factor, 6);
        if (bccomp(bcmul($qty, $factor, 16), $base, 16) || bccomp($base, bcadd($base, '0', $item->baseUnit->decimal_places), 6) || bccomp($base, '99999999999999.999999', 6) > 0) {
            Guard::fail('Conversion exceeds base-unit precision/range.');
        }

        return [$qty, $base, bcadd($factor, '0', 8)];
    }

    private function item(int $id): Item
    {
        $item = Item::with('baseUnit')->findOrFail($id);
        if (! $item->is_active || ! $item->is_stocked || ! $item->baseUnit->is_active || ! in_array($item->item_type, ['grey_fabric', 'processed_fabric', 'trim', 'packaging', 'scrap'], true)) {
            Guard::fail('Choose an active raw fabric or material stock item.');
        }

        return $item;
    }

    public function save(int $actor, array $input, ?int $id = null): Document
    {
        return $this->transaction($actor, 'purchasing.documents.manage', function () use ($actor, $input, $id) {
            $data = validator($input, [
                'kind' => 'required|in:order,receipt,return,bill', 'direct_receipt' => 'sometimes|boolean', 'direct_receipt_reason' => 'required_if:direct_receipt,1|nullable|string|min:5|max:500', 'document_date' => 'required|date_format:Y-m-d', 'idempotency_key' => 'required|uuid', 'lock_version' => 'sometimes|integer|min:0',
                'supplier_party_role_id' => 'required|integer|exists:party_roles,id', 'warehouse_id' => 'required_unless:kind,bill|nullable|integer|exists:warehouses,id',
                'payment_term_id' => 'nullable|integer|exists:payment_terms,id', 'expected_on' => 'nullable|date_format:Y-m-d', 'supplier_reference' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:5000',
                'supplier_challan_number' => 'nullable|string|max:100', 'supplier_challan_date' => 'nullable|date_format:Y-m-d', 'received_at' => 'nullable|date',
                'return_reference' => 'nullable|string|max:100', 'reason' => 'required_if:kind,return|nullable|string|min:5|max:500',
                'supplier_invoice_number' => 'required_if:kind,bill|nullable|string|max:100', 'supplier_invoice_date' => 'required_if:kind,bill|nullable|date_format:Y-m-d',
                'due_on' => 'required_if:kind,bill|nullable|date_format:Y-m-d|after_or_equal:supplier_invoice_date', 'lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array',
                'tax_amount' => 'prohibited', 'rounding_amount' => 'prohibited', 'gross_amount' => 'prohibited',
            ])->validate();
            if (! empty($data['direct_receipt']) && $data['kind'] !== 'receipt') {
                Guard::fail('The without-order option is only available for goods receipts.');
            }
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            if (! $id && ($existing = Document::where('idempotency_key', $data['idempotency_key'])->first())) {
                if ($existing->type->module_code !== 'purchasing') {
                    Guard::fail('This request key is already used by another module.');
                }
                $audit = AuditEvent::where('document_id', $existing->id)->where('action', 'purchase_draft_created')->first();
                if (($audit?->after_json['input_hash'] ?? null) !== $hash) {
                    Guard::fail('Creation key was reused for different data.');
                }

                return $existing;
            }
            [$year,$period] = $this->documents->calendar($data['document_date']);
            $role = $this->supplier($data['supplier_party_role_id']);
            if ($data['kind'] !== 'bill') {
                $this->warehouse($data['warehouse_id']);
            }
            $doc = $id ? $this->documents->find($id) : new Document;
            $type = DocumentType::where('code', self::TYPES[$data['kind']])->where('is_active', true)->firstOrFail();
            if ($id) {
                $this->draft($doc, (int) ($data['lock_version'] ?? -1));
                if ($doc->document_type_id !== $type->id || $doc->idempotency_key !== $data['idempotency_key']) {
                    Guard::fail('Document kind and creation key cannot change.');
                }
                if ($this->workflow($doc) === 'submitted') {
                    Guard::fail('Return the submitted order to draft before editing.');
                }
                if ($data['kind'] === 'bill') {
                    DB::table('purchase_invoice_receipt_matches')->whereIn('purchase_invoice_line_id', PurchaseInvoiceLine::where('document_id', $id)->pluck('id'))->delete();
                }
                $header = $this->header($doc);
                DB::table($header->lines()->getRelated()->getTable())->where('document_id', $id)->delete();
            }
            $doc->fill(['document_type_id' => $type->id, 'document_date' => $data['document_date'], 'financial_year_id' => $year->id, 'accounting_period_id' => $period->id]);
            if (! $id) {
                $doc->fill(['status' => 'draft', 'created_by' => $actor, 'idempotency_key' => $data['idempotency_key'], 'lock_version' => 0]);
            } else {
                $doc->lock_version++;
            }
            $doc->save();
            match ($data['kind']) {
                'order' => $this->prepareOrder($doc, $data, $role),
                'receipt' => $this->prepareReceipt($doc, $data, $role),
                'return' => $this->prepareReturn($doc, $data, $role),
                'bill' => $this->prepareBill($doc, $data, $role),
            };
            $this->audit->record('documents', $doc->id, $id ? 'purchase_draft_updated' : 'purchase_draft_created', null, ['input_hash' => $hash, 'payload' => $this->payload($doc)], $actor, $doc->id);
            if (! empty($data['direct_receipt'])) {
                $this->audit->record('documents', $doc->id, 'purchase_direct_receipt_reason', null, ['reason' => $data['direct_receipt_reason']], $actor, $doc->id);
            }
            if (! $id) {
                $this->documents->event($doc, 'created', $actor, $doc->idempotency_key, null, 'draft');
            }

            return $doc->fresh();
        });
    }

    private function prepareOrder(Document $doc, array $data, PartyRole $role): void
    {
        PurchaseOrder::updateOrCreate(['document_id' => $doc->id], ['supplier_party_role_id' => $role->id, 'ship_to_warehouse_id' => $data['warehouse_id'], 'payment_term_id' => $data['payment_term_id'] ?? $role->supplierProfile->payment_term_id, 'expected_on' => $data['expected_on'] ?? null, 'supplier_reference' => $data['supplier_reference'] ?? null, 'notes' => $data['notes'] ?? null]);
        foreach ($data['lines'] as $i => $input) {
            $line = validator($input, ['item_id' => 'required|integer|exists:items,id', 'description' => 'nullable|string|max:255', 'unit_id' => 'required|integer|exists:units,id', 'quantity' => 'required', 'unit_price' => 'required', 'discount_amount' => 'nullable', 'expected_on' => 'nullable|date_format:Y-m-d', 'tax_rate_id' => 'prohibited'])->validate();
            $item = $this->item($line['item_id']);
            [$qty,$base,$factor] = $this->snapshot($item, $line['unit_id'], (string) $line['quantity']);
            $price = D::input($line['unit_price'], 8);
            $discount = D::input($line['discount_amount'] ?? '0', 4);
            $this->money(bcsub(D::round(bcmul($qty, $price, 14)), $discount, 4));
            PurchaseOrderLine::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'item_id' => $item->id, 'description' => $line['description'] ?? $item->name, 'unit_id' => $line['unit_id'], 'quantity' => $qty, 'base_quantity' => $base, 'conversion_factor' => $factor, 'unit_price' => $price, 'discount_amount' => $discount, 'expected_on' => $line['expected_on'] ?? $data['expected_on'] ?? null]);
        }
    }

    public function accepted(int $orderLine): string
    {
        return bcadd((string) GoodsReceiptLine::where('purchase_order_line_id', $orderLine)->whereHas('receipt.document', fn ($q) => $q->where('status', 'posted'))->sum('accepted_base_quantity'), '0', 6);
    }

    public function returned(int $receiptLine): string
    {
        return bcadd((string) PurchaseReturnLine::where('goods_receipt_line_id', $receiptLine)->whereHas('document', fn ($q) => $q->where('status', 'posted'))->sum('base_quantity'), '0', 6);
    }

    public function matched(int $receiptLine): string
    {
        return bcadd((string) DB::table('purchase_invoice_receipt_matches as m')->join('purchase_invoice_lines as l', 'l.id', '=', 'm.purchase_invoice_line_id')->join('documents as d', 'd.id', '=', 'l.document_id')->where('m.goods_receipt_line_id', $receiptLine)->where('d.status', 'posted')->sum('m.matched_base_quantity'), '0', 6);
    }

    private function approvedLine(int $id, int $supplier, int $warehouse): PurchaseOrderLine
    {
        $line = PurchaseOrderLine::with('order.document')->lockForUpdate()->findOrFail($id);
        if ($line->order->document->status !== 'posted' || $line->order->supplier_party_role_id !== $supplier || $line->order->ship_to_warehouse_id !== $warehouse || $this->closed($line->document_id)) {
            Guard::fail('Receipt requires an approved, open order for this supplier and warehouse.');
        }

        return $line;
    }

    private function prepareReceipt(Document $doc, array $data, PartyRole $role): void
    {
        $direct = (bool) ($data['direct_receipt'] ?? false);
        GoodsReceipt::updateOrCreate(['document_id' => $doc->id], ['supplier_party_role_id' => $role->id, 'warehouse_id' => $data['warehouse_id'], 'supplier_challan_number' => $data['supplier_challan_number'] ?? null, 'supplier_challan_date' => $data['supplier_challan_date'] ?? null, 'received_at' => $data['received_at'] ?? now(), 'notes' => $data['notes'] ?? null]);
        $seen = [];
        $quantities = [];
        foreach ($data['lines'] as $i => $input) {
            $rules = ['purchase_order_line_id' => $direct ? 'prohibited' : 'required|integer|exists:purchase_order_lines,id', 'accepted_quantity' => 'required', 'rejected_quantity' => 'nullable', 'warehouse_location_id' => 'required|integer|exists:warehouse_locations,id', 'lot_number' => 'required|string|max:50', 'roll_number' => 'nullable|string|max:60', 'width_mm' => 'nullable|decimal:0,3|gt:0|max:999999999', 'quality_status' => 'required|in:pending,released,held,rejected', 'inspection_notes' => 'nullable|string|max:500', 'external_lot_reference' => 'nullable|string|max:100', 'external_roll_reference' => 'nullable|string|max:100'];
            if ($direct) {
                $rules += ['item_id' => 'required|integer|exists:items,id', 'unit_id' => 'required|integer|exists:units,id', 'unit_cost' => 'required|numeric|gt:0'];
            }
            $line = validator($input, $rules)->validate();
            $po = $direct ? null : $this->approvedLine($line['purchase_order_line_id'], $role->id, $data['warehouse_id']);
            $item = $this->item($direct ? (int) $line['item_id'] : $po->item_id);
            if ($po && $po->order->document->document_date->gt($doc->document_date)) {
                Guard::fail('Receipt cannot predate order approval date.');
            }
            $unitId = $direct ? (int) $line['unit_id'] : $po->unit_id;
            [$qty,$base,$factor] = $this->snapshot($item, $unitId, (string) $line['accepted_quantity'], $po?->conversion_factor);
            $rejected = D::input($line['rejected_quantity'] ?? '0');
            if (bccomp($rejected, bcadd($rejected, '0', Unit::findOrFail($unitId)->decimal_places), 6)) {
                Guard::fail('Rejected quantity exceeds unit precision.');
            }
            if (bccomp($rejected, '0', 6) > 0 && blank($line['inspection_notes'] ?? null)) {
                Guard::fail('Explain rejected quantities in inspection notes.');
            }
            if ($po) {
                $quantities[$po->id] = bcadd($quantities[$po->id] ?? '0', $base, 6);
                if (bccomp(bcadd($this->accepted($po->id), $quantities[$po->id], 6), $po->base_quantity, 6) > 0) {
                    Guard::fail('Zero tolerance: accepted receipts cannot exceed the approved order.');
                }
            }
            $location = WarehouseLocation::findOrFail($line['warehouse_location_id']);
            if ($location->warehouse_id !== (int) $data['warehouse_id'] || ! in_array($location->location_type, ['internal', 'quarantine'], true)) {
                Guard::fail('Choose internal/quarantine storage in the receiving warehouse.');
            }
            $lot = StockLot::firstOrCreate(['lot_number' => $line['lot_number']], ['item_id' => $item->id, 'origin_document_id' => $doc->id, 'quality_status' => $line['quality_status'], 'external_lot_reference' => $line['external_lot_reference'] ?? null]);
            if ($lot->item_id !== $item->id || $lot->origin_document_id !== $doc->id) {
                Guard::fail('Use a new lot number for this receipt, preserving its source document.');
            }
            $roll = null;
            if ($item->track_rolls) {
                if (blank($line['roll_number'] ?? null)) {
                    Guard::fail('Identify each physical roll on its own receipt line.');
                }
                $roll = FabricRoll::firstOrCreate(['roll_number' => $line['roll_number']], ['stock_lot_id' => $lot->id, 'width_mm' => $line['width_mm'] ?? null, 'external_roll_reference' => $line['external_roll_reference'] ?? null]);
                if ($roll->stock_lot_id !== $lot->id) {
                    Guard::fail('The roll number already belongs to another lot.');
                }
            } elseif (filled($line['roll_number'] ?? null)) {
                Guard::fail('This item does not track rolls.');
            }
            $position = StockPosition::firstOrCreate(['stock_lot_id' => $lot->id, 'warehouse_location_id' => $location->id, 'fabric_roll_id' => $roll?->id]);
            StockBalance::firstOrCreate(['stock_position_id' => $position->id], ['on_hand_quantity' => 0, 'reserved_quantity' => 0]);
            $this->stock->position($position->id, $doc->document_date->format('Y-m-d'));
            if (isset($seen[$position->id])) {
                Guard::fail('Use one receipt line per lot/roll/location position.');
            } $seen[$position->id] = true;
            if ($direct) {
                $cost = D::input((string) $line['unit_cost'], 8);
                if (bccomp($cost, '0', 8) <= 0) {
                    Guard::fail('Enter the supplier cost per base unit for a direct receipt.');
                }
            } else {
                $orderNet = bcsub(D::round(bcmul($po->quantity, $po->unit_price, 14)), $po->discount_amount, 4);
                $cost = D::round(bcdiv($orderNet, $po->base_quantity, 16), 8);
            }
            $value = $this->money(D::round(bcmul($base, $cost, 14)));
            GoodsReceiptLine::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'purchase_order_line_id' => $po?->id, 'item_id' => $item->id, 'stock_position_id' => $position->id, 'unit_id' => $unitId, 'received_quantity' => bcadd($qty, $rejected, 6), 'accepted_quantity' => $qty, 'rejected_quantity' => $rejected, 'conversion_factor' => $factor, 'accepted_base_quantity' => $base, 'provisional_unit_cost' => $cost, 'provisional_value' => $value, 'inspection_notes' => $line['inspection_notes'] ?? null]);
        }
    }

    private function receipt(int $id, int $supplier): GoodsReceiptLine
    {
        $line = GoodsReceiptLine::with(['receipt.document', 'position.lot.item.baseUnit'])->lockForUpdate()->findOrFail($id);
        if ($line->receipt->supplier_party_role_id !== $supplier || $line->receipt->document->status !== 'posted') {
            Guard::fail('Select a posted receipt for this supplier.');
        }

        return $line;
    }

    private function prepareReturn(Document $doc, array $data, PartyRole $role): void
    {
        PurchaseReturn::updateOrCreate(['document_id' => $doc->id], ['supplier_party_role_id' => $role->id, 'warehouse_id' => $data['warehouse_id'], 'return_reference' => $data['return_reference'] ?? null, 'reason' => $data['reason']]);
        $quantities = [];
        foreach ($data['lines'] as $i => $input) {
            $line = validator($input, ['goods_receipt_line_id' => 'required|integer|exists:goods_receipt_lines,id', 'stock_position_id' => 'required|integer|exists:stock_positions,id', 'base_quantity' => 'required', 'reason' => 'required|string|min:5|max:500'])->validate();
            $receipt = $this->receipt($line['goods_receipt_line_id'], $role->id);
            $position = $this->stock->position($line['stock_position_id'], $doc->document_date->format('Y-m-d'));
            if ($position->stock_lot_id !== $receipt->position->stock_lot_id || $position->fabric_roll_id !== $receipt->position->fabric_roll_id || $position->location->warehouse_id !== (int) $data['warehouse_id']) {
                Guard::fail('Return the original lot/roll item from the selected warehouse.');
            }
            $qty = $this->stock->quantity($position, $line['base_quantity']);
            $quantities[$receipt->id] = bcadd($quantities[$receipt->id] ?? '0', $qty, 6);
            if (bccomp(bcadd($this->returned($receipt->id), $quantities[$receipt->id], 6), $receipt->accepted_base_quantity, 6) > 0) {
                Guard::fail('Return exceeds the original accepted quantity.');
            }
            PurchaseReturnLine::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'goods_receipt_line_id' => $receipt->id, 'stock_position_id' => $position->id, 'base_quantity' => $qty, 'reason' => $line['reason']]);
        }
    }

    private function prepareBill(Document $doc, array $data, PartyRole $role): void
    {
        $reference = mb_strtoupper(preg_replace('/\s+/u', ' ', trim($data['supplier_invoice_number'])));
        if ($reference === '') {
            Guard::fail('Supplier bill reference is required.');
        }
        $this->duplicateBill($role->party_id, $reference, $doc->id);
        $estimate = '0';
        foreach ($data['lines'] as $input) {
            $quantity = D::input($input['quantity'] ?? '');
            $price = D::input($input['unit_price'] ?? '', 8);
            $discount = D::input($input['discount_amount'] ?? '0', 4);
            $estimate = bcadd($estimate, $this->money(bcsub(D::round(bcmul($quantity, $price, 14)), $discount, 4)), 4);
        }
        if (bccomp($estimate, '0', 4) <= 0) {
            Guard::fail('A payable bill must have positive value.');
        }
        $header = PurchaseInvoice::updateOrCreate(['document_id' => $doc->id], ['payee_party_role_id' => $role->id, 'supplier_invoice_number' => $reference, 'supplier_invoice_date' => $data['supplier_invoice_date'], 'due_on' => $data['due_on'], 'payment_term_id' => $data['payment_term_id'] ?? $role->supplierProfile->payment_term_id, 'net_amount' => $estimate, 'gross_amount' => $estimate, 'notes' => $data['notes'] ?? null]);
        // Header CHECK requires a positive gross: calculate lines first, then persist the final total.
        $total = '0';
        $quantities = [];
        foreach ($data['lines'] as $i => $input) {
            $line = validator($input, ['goods_receipt_line_id' => 'required|integer|exists:goods_receipt_lines,id', 'quantity' => 'required', 'unit_price' => 'required', 'discount_amount' => 'nullable', 'description' => 'nullable|string|max:255'])->validate();
            $receipt = $this->receipt($line['goods_receipt_line_id'], $role->id);
            $item = $this->item($receipt->item_id);
            [$qty,$base,$factor] = $this->snapshot($item, $item->base_unit_id, (string) $line['quantity'], '1');
            $price = D::input($line['unit_price'], 8);
            $discount = D::input($line['discount_amount'] ?? '0', 4);
            $net = $this->money(bcsub(D::round(bcmul($qty, $price, 14)), $discount, 4));
            $quantities[$receipt->id] = bcadd($quantities[$receipt->id] ?? '0', $base, 6);
            if (bccomp(bcadd($this->matched($receipt->id), bcadd($this->returned($receipt->id), $quantities[$receipt->id], 6), 6), $receipt->accepted_base_quantity, 6) > 0) {
                Guard::fail('Bill quantity exceeds the accepted, unreturned, unbilled receipt quantity.');
            }
            $invoiceLine = PurchaseInvoiceLine::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'line_kind' => 'stock', 'item_id' => $item->id, 'description' => $line['description'] ?? $item->name, 'unit_id' => $item->base_unit_id, 'quantity' => $qty, 'base_quantity' => $base, 'conversion_factor' => $factor, 'unit_price' => $price, 'discount_amount' => $discount, 'net_amount' => $net, 'tax_amount' => 0, 'gross_amount' => $net]);
            PurchaseInvoiceReceiptMatch::create(['purchase_invoice_line_id' => $invoiceLine->id, 'goods_receipt_line_id' => $receipt->id, 'matched_base_quantity' => $base, 'net_amount' => $net, 'provisional_value_relieved' => 0]);
            $total = bcadd($total, $net, 4);
        }
        if (bccomp($total, '0', 4) <= 0) {
            Guard::fail('A payable bill must have positive value.');
        }
        $header->update(['net_amount' => $this->money($total), 'gross_amount' => $total]);
    }

    private function duplicateBill(int $party, string $reference, int $except): void
    {
        if (PurchaseInvoice::where('supplier_invoice_number', $reference)->where('document_id', '<>', $except)->whereHas('supplier', fn ($q) => $q->where('party_id', $party))->exists()) {
            Guard::fail('This supplier identity already has that bill reference.');
        }
    }

    private function payload(Document $doc): array
    {
        $header = $this->header($doc);
        $strip = ['created_at', 'updated_at', 'document_id'];
        $head = array_diff_key($header->getAttributes(), array_flip($strip));
        $lines = [];
        foreach ($header->lines as $line) {
            $row = array_diff_key($line->getAttributes(), array_flip([...$strip, 'id', 'stock_movement_id']));
            if ($line instanceof PurchaseInvoiceLine) {
                $row['matches'] = $line->matches()->orderBy('goods_receipt_line_id')->get()->map(fn ($m) => $m->only(['goods_receipt_line_id', 'matched_base_quantity', 'net_amount']))->all();
            }
            $lines[] = $row;
        }

        return ['type' => $doc->document_type_id, 'date' => $doc->document_date->format('Y-m-d'), 'year' => $doc->financial_year_id, 'period' => $doc->accounting_period_id, 'header' => $head, 'lines' => $lines];
    }

    /** Save and finalize together; the existing posting rules remain authoritative. */
    public function confirm(int $actor, array $input, ?int $id = null): Document
    {
        validator($input, ['kind' => 'required|in:order,receipt,return,bill'])->validate();

        return $this->transaction($actor, self::PERMISSIONS[$input['kind']], function () use ($actor, $input, $id) {
            $doc = $this->save($actor, $input, $id);
            $key = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'textile:purchasing:confirm:'.$input['idempotency_key']);

            return $this->confirmExisting($actor, $doc->id, $key, $doc->lock_version);
        });
    }

    public function confirmExisting(int $actor, int $id, string $key, int $version): Document
    {
        $kind = $this->kind(Document::with('type')->findOrFail($id));

        return $this->transaction($actor, self::PERMISSIONS[$kind], function () use ($actor, $id, $key, $version, $kind) {
            $doc = $this->documents->find($id);
            if ($doc->status !== 'posted') {
                $this->draft($doc, $version);
                if ($kind === 'order' && $this->workflow($doc) === 'draft') {
                    $this->submit($actor, $id, $version, (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'textile:submit:'.$key));
                    $version = $doc->fresh()->lock_version;
                }
            }

            return $this->post($actor, $id, $key, $version);
        });
    }

    public function submit(int $actor, int $id, int $version, string $key): void
    {
        $this->transaction($actor, 'purchasing.documents.manage', function () use ($actor, $id, $version, $key) {
            validator(['key' => $key], ['key' => 'uuid'])->validate();
            $doc = $this->documents->find($id);
            $this->draft($doc, $version);
            if ($this->kind($doc) !== 'order' || $this->workflow($doc) !== 'draft') {
                Guard::fail('Only a draft order can be submitted.');
            }
            $this->supplier($this->header($doc)->supplier_party_role_id);
            $doc->increment('lock_version');
            $this->documents->event($doc, 'order_submitted', $actor, $key, 'draft', 'draft', 'Submitted for approval');
        });
    }

    public function post(int $actor, int $id, string $key, int $version): Document
    {
        $peek = Document::with('type')->findOrFail($id);
        $kind = $this->kind($peek);
        validator(['key' => $key], ['key' => 'required|uuid'])->validate();

        return $this->transaction($actor, self::PERMISSIONS[$kind], function () use ($actor, $id, $key, $version, $kind) {
            $doc = $this->documents->find($id);
            $hash = hash('sha256', json_encode($this->payload($doc), JSON_THROW_ON_ERROR), true);
            if ($doc->status === 'posted') {
                if ($doc->posting_key === $key && hash_equals($doc->getRawOriginal('payload_hash'), $hash)) {
                    return $doc;
                }
                Guard::fail('Already posted under a different request or payload.');
            }
            $this->draft($doc, $version);
            $this->documents->calendar($doc->document_date->format('Y-m-d'));
            if (Document::where('posting_key', $key)->exists()) {
                Guard::fail('Posting key is already used.');
            }
            $header = $this->header($doc);
            $supplier = $this->supplier($kind === 'bill' ? $header->payee_party_role_id : $header->supplier_party_role_id);
            if ($header->lines->isEmpty()) {
                Guard::fail('A document requires lines.');
            }
            $positions = [];
            if ($kind === 'order') {
                if ($this->workflow($doc) !== 'submitted') {
                    Guard::fail('Submit the order before approval.');
                }
                $this->warehouse($header->ship_to_warehouse_id);
                foreach ($header->lines as $line) {
                    $this->item($line->item_id);
                }
            } elseif ($kind === 'receipt') {
                $positions = $this->postReceipt($doc, $header);
            } elseif ($kind === 'return') {
                $positions = $this->postReturn($doc, $header);
            } else {
                $this->postBill($doc, $header, $supplier);
            }
            $this->stock->validateRolls($positions);
            foreach (array_unique($positions) as $position) {
                $this->stock->refresh($position);
            }
            $this->documents->finish($doc, $actor, $key, $hash);

            return $doc->fresh();
        });
    }

    private function postReceipt(Document $doc, GoodsReceipt $header): array
    {
        $this->warehouse($header->warehouse_id);
        $positions = [];
        $totals = [];
        $value = '0';
        foreach ($header->lines as $line) {
            if ($line->purchase_order_line_id) {
                $po = $this->approvedLine($line->purchase_order_line_id, $header->supplier_party_role_id, $header->warehouse_id);
                $totals[$po->id] = bcadd($totals[$po->id] ?? '0', $line->accepted_base_quantity, 6);
                if (bccomp(bcadd($this->accepted($po->id), $totals[$po->id], 6), $po->base_quantity, 6) > 0 || $line->item_id !== $po->item_id) {
                    Guard::fail('Posting would over-receive the order or the receipt item does not match it. Zero tolerance applies.');
                }
            } else {
                $this->item($line->item_id);
                if (bccomp($line->provisional_unit_cost, '0', 8) <= 0) {
                    Guard::fail('Direct receipts require a positive actual supplier cost.');
                }
            }
            $position = $this->stock->position($line->stock_position_id, $doc->document_date->format('Y-m-d'));
            if ($position->lot->origin_document_id !== $doc->id || $position->lot->item_id !== $line->item_id || $position->movements()->exists() || $position->location->warehouse_id !== $header->warehouse_id) {
                Guard::fail('Receipt lot/position is not an unused, matching source identity.');
            }
            $movement = $this->stock->movement($doc, $position->id, $line->accepted_base_quantity, 'purchase_receipt');
            $this->stock->receipt($doc, $movement, $line->accepted_base_quantity, $line->provisional_value);
            $line->update(['stock_movement_id' => $movement->id]);
            $positions[] = $position->id;
            $value = bcadd($value, $line->provisional_value, 4);
        }
        $this->journals->post($doc, [$this->documents->account('inventory') => $value, $this->documents->account('grni') => D::negative($value, 4)]);

        return $positions;
    }

    private function postReturn(Document $doc, PurchaseReturn $header): array
    {
        $this->warehouse($header->warehouse_id);
        $positions = [];
        $returned = [];
        $required = [];
        $validated = [];
        foreach ($header->lines as $line) {
            $required[$line->stock_position_id] = bcadd($required[$line->stock_position_id] ?? '0', $line->base_quantity, 6);
        }
        foreach ($required as $id => $qty) {
            $position = $this->stock->position($id, $doc->document_date->format('Y-m-d'));
            if ($position->location->warehouse_id !== $header->warehouse_id || bccomp($qty, $this->stock->available($id), 6) > 0) {
                Guard::fail('Return exceeds available stock in this warehouse.');
            }
            if ($position->fabric_roll_id && bccomp($qty, $this->stock->onHand($id), 6)) {
                Guard::fail('Return the whole tracked roll; splitting is a separate future workflow.');
            }
            $validated[$id] = $position;
        }
        $inventory = $this->documents->account('inventory');
        $journal = [$inventory => '0'];
        foreach ($header->lines as $line) {
            $receipt = $this->receipt($line->goods_receipt_line_id, $header->supplier_party_role_id);
            $position = $validated[$line->stock_position_id];
            if ($receipt->receipt->document->document_date->gt($doc->document_date) || $position->stock_lot_id !== $receipt->position->stock_lot_id || $position->fabric_roll_id !== $receipt->position->fabric_roll_id) {
                Guard::fail('Return must trace to an earlier original supplier receipt.');
            }
            $returned[$receipt->id] = bcadd($returned[$receipt->id] ?? '0', $line->base_quantity, 6);
            if (bccomp(bcadd($this->returned($receipt->id), $returned[$receipt->id], 6), $receipt->accepted_base_quantity, 6) > 0) {
                Guard::fail('Cumulative return exceeds accepted receipt.');
            }
            // Never reduce AP here. Fully unbilled receipts relieve GRNI; otherwise await a supplier credit.
            $offset = $this->documents->account(bccomp($this->matched($receipt->id), '0', 6) === 0 ? 'grni' : 'supplier_return_clearing');
            $out = $this->stock->movement($doc, $position->id, D::negative($line->base_quantity), 'purchase_return');
            $cost = '0';
            foreach ($this->stock->plan($position->id, $line->base_quantity, $doc->document_date->format('Y-m-d')) as [$layer,$take]) {
                [$allocation,$amount] = $this->stock->issue($doc, $out, $layer, $take);
                $this->stock->link($doc, $allocation, $amount, null, $offset);
                $cost = bcadd($cost, $amount, 4);
            }
            $line->update(['stock_movement_id' => $out->id]);
            $journal[$inventory] = bcsub($journal[$inventory], $cost, 4);
            $journal[$offset] = bcadd($journal[$offset] ?? '0', $cost, 4);
            $positions[] = $position->id;
        }
        $this->journals->post($doc, $journal);

        return $positions;
    }

    private function postBill(Document $doc, PurchaseInvoice $header, PartyRole $supplier): void
    {
        $this->duplicateBill($supplier->party_id, $header->supplier_invoice_number, $doc->id);
        $used = [];
        $usedValue = [];
        $total = '0';
        foreach ($header->lines as $line) {
            if ($line->matches->count() !== 1) {
                Guard::fail('Each basic stock bill line requires one receipt match.');
            }
            $match = $line->matches->first();
            $receipt = $this->receipt($match->goods_receipt_line_id, $supplier->id);
            if ($receipt->item_id !== $line->item_id || $receipt->receipt->document->document_date->gt($doc->document_date)) {
                Guard::fail('Bill item/date does not match its receipt.');
            }
            $used[$receipt->id] = bcadd($used[$receipt->id] ?? '0', $line->base_quantity, 6);
            $previous = $this->matched($receipt->id);
            $returns = $this->returned($receipt->id);
            if (bccomp(bcadd($previous, bcadd($returns, $used[$receipt->id], 6), 6), $receipt->accepted_base_quantity, 6) > 0) {
                Guard::fail('Receipt is already billed or returned.');
            }
            $previousValue = (string) DB::table('purchase_invoice_receipt_matches as m')->join('purchase_invoice_lines as l', 'l.id', '=', 'm.purchase_invoice_line_id')->join('documents as d', 'd.id', '=', 'l.document_id')->where('m.goods_receipt_line_id', $receipt->id)->where('d.status', 'posted')->sum('m.provisional_value_relieved');
            // A returned, unbilled portion has already relieved GRNI at its actual stock value.
            $returnedValue = '0';
            foreach (PurchaseReturnLine::where('goods_receipt_line_id', $receipt->id)->whereHas('document', fn ($q) => $q->where('status', 'posted'))->get() as $returnedLine) {
                $links = InventoryLayerAllocation::where('stock_movement_id', $returnedLine->stock_movement_id)->pluck('id');
                $returnedValue = bcadd($returnedValue, (string) InventoryCostLink::whereIn('source_layer_allocation_id', $links)->where('expense_account_id', $this->documents->account('grni'))->sum('allocated_cost_pkr'), 4);
            }
            $relief = D::round(bcmul($line->base_quantity, $receipt->provisional_unit_cost, 14));
            if (bccomp(bcadd($previous, bcadd($returns, $used[$receipt->id], 6), 6), $receipt->accepted_base_quantity, 6) === 0) {
                $relief = bcsub(bcsub(bcsub($receipt->provisional_value, $previousValue, 4), $usedValue[$receipt->id] ?? '0', 4), $returnedValue, 4);
            }
            if (bccomp($line->net_amount, $relief, 4) || bccomp($match->net_amount, $line->net_amount, 4) || bccomp($match->matched_base_quantity, $line->base_quantity, 6)) {
                Guard::fail('This basic bill must match original receipt cost exactly. Price variances require the future cost-adjustment workflow.');
            }
            $usedValue[$receipt->id] = bcadd($usedValue[$receipt->id] ?? '0', $relief, 4);
            $match->update(['provisional_value_relieved' => $relief]);
            $total = bcadd($total, $line->net_amount, 4);
        }
        if (bccomp($total, $header->gross_amount, 4) || bccomp($total, '0', 4) <= 0) {
            Guard::fail('Supplier bill totals do not reconcile.');
        }
        $this->journals->post($doc, [$this->documents->account('grni') => $total, $this->documents->account('supplier_payable') => D::negative($total, 4)], 'operational', $supplier->party_id, $supplier->id, $header->due_on->format('Y-m-d'));
    }

    public function cancel(int $actor, int $id, int $version, string $reason): void
    {
        validator(['reason' => $reason], ['reason' => 'required|string|min:5|max:500'])->validate();
        $this->transaction($actor, 'purchasing.documents.manage', function () use ($actor, $id, $version, $reason) {
            $doc = $this->documents->find($id);
            $this->draft($doc, $version);
            $doc->update(['status' => 'cancelled', 'reason' => $reason, 'lock_version' => $doc->lock_version + 1]);
            $this->documents->event($doc, 'cancelled', $actor, (string) Str::uuid(), 'draft', 'cancelled', $reason);
        });
    }

    public function orderDecision(int $actor, int $id, int $version, string $action, string $reason): void
    {
        validator(['reason' => $reason, 'action' => $action], ['reason' => 'required|string|min:5|max:500', 'action' => 'in:return_to_draft,close'])->validate();
        $this->transaction($actor, 'purchasing.orders.approve', function () use ($actor, $id, $version, $action, $reason) {
            $doc = $this->documents->find($id);
            if ($this->kind($doc) !== 'order') {
                Guard::fail('Select a purchase order.');
            }
            if ($action === 'close') {
                if ($doc->status !== 'posted') {
                    Guard::fail('Only an approved order can close its unfulfilled balance.');
                }
                if ($this->closed($id)) {
                    return;
                }
                $this->documents->event($doc, 'order_closed', $actor, (string) Str::uuid(), 'posted', 'posted', $reason);
            } else {
                $this->draft($doc, $version);
                if ($this->workflow($doc) !== 'submitted') {
                    Guard::fail('The order is not submitted.');
                }
                $doc->increment('lock_version');
                $this->documents->event($doc, 'order_returned', $actor, (string) Str::uuid(), 'draft', 'draft', $reason);
            }
        });
    }

    public function rejectedDelivery(int $actor, int $id, array $input): void
    {
        $data = validator($input, ['purchase_order_line_id' => 'required|integer|exists:purchase_order_lines,id', 'rejected_quantity' => 'required', 'supplier_challan_number' => 'required|string|max:100', 'reason' => 'required|string|min:5|max:500'])->validate();
        $this->transaction($actor, 'purchasing.receipts.post', function () use ($actor, $id, $data) {
            $doc = $this->documents->find($id);
            if ($this->kind($doc) !== 'order' || $doc->status !== 'posted' || $this->closed($id)) {
                Guard::fail('Select an approved open purchase order.');
            }
            $line = PurchaseOrderLine::where('document_id', $id)->findOrFail($data['purchase_order_line_id']);
            [$qty] = $this->snapshot($this->item($line->item_id), $line->unit_id, (string) $data['rejected_quantity'], $line->conversion_factor);
            $this->audit->record('documents', $id, 'delivery_rejected', null, $data + ['quantity' => $qty], $actor, $id);
        });
    }
}

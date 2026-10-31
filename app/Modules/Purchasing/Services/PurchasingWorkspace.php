<?php

namespace App\Modules\Purchasing\Services;

use App\Models\Document;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\StockPosition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only screen context. PurchasingService remains the posting authority. */
class PurchasingWorkspace
{
    public const PAGES = [
        'order' => ['path' => 'orders', 'title' => 'Purchase orders', 'new' => 'New purchase order', 'help' => 'Agree what to buy from a shared supplier. Confirm the order, then receive its goods. Confirmation creates no stock or payable.'],
        'receipt' => ['path' => 'receipts', 'title' => 'Goods receipts', 'new' => 'Receive purchased goods', 'help' => 'Record the fabric physically delivered against approved orders. Accepted stock enters your warehouse when the GRN is posted.'],
        'return' => ['path' => 'returns', 'title' => 'Purchase returns', 'new' => 'Return goods to supplier', 'help' => 'Return available original receipt stock to its supplier. A physical return does not pay or reduce an existing supplier bill.'],
        'bill' => ['path' => 'bills', 'title' => 'Supplier bills', 'new' => 'New supplier bill', 'help' => 'Match the supplier invoice to accepted, unreturned, unbilled receipt quantities. Confirming records the payable; it does not receive stock or make a payment.'],
    ];

    public static function route(string $kind, string $action = 'index'): string
    {
        return 'purchasing.'.self::PAGES[$kind]['path'].'.'.$action;
    }

    public function context(Request $request, string $kind, mixed $header, bool $editing): array
    {
        $filter = $request->validate(['order' => 'nullable|integer|min:1', 'receipt' => 'nullable|integer|min:1', 'supplier' => 'nullable|integer|min:1', 'warehouse' => 'nullable|integer|min:1']);
        $orders = PurchaseOrder::with('document', 'supplier.party', 'warehouse')->whereHas('document', fn ($q) => $q->where('status', 'posted'))->latest('document_id')->get();
        $receipts = GoodsReceipt::with('document', 'supplier.party')->whereHas('document', fn ($q) => $q->where('status', 'posted'))->latest('document_id')->get();
        $selectedOrder = ! $editing && $kind === 'receipt' ? $orders->firstWhere('document_id', (int) ($filter['order'] ?? 0)) : null;
        $selectedReceipt = ! $editing && in_array($kind, ['return', 'bill']) ? $receipts->firstWhere('document_id', (int) ($filter['receipt'] ?? 0)) : null;
        abort_if(! $editing && $kind === 'receipt' && ! empty($filter['order']) && ! $selectedOrder, 404);
        abort_if(! $editing && in_array($kind, ['return', 'bill']) && ! empty($filter['receipt']) && ! $selectedReceipt, 404);
        $supplierId = (int) ($header?->supplier_party_role_id ?? $header?->payee_party_role_id ?? $selectedOrder?->supplier_party_role_id ?? $selectedReceipt?->supplier_party_role_id ?? old('supplier_party_role_id', $filter['supplier'] ?? 0));
        $warehouseId = (int) ($header?->warehouse_id ?? $header?->ship_to_warehouse_id ?? $selectedOrder?->ship_to_warehouse_id ?? ($kind === 'return' ? ($filter['warehouse'] ?? $selectedReceipt?->warehouse_id) : $selectedReceipt?->warehouse_id) ?? old('warehouse_id', $filter['warehouse'] ?? 0));
        $contextReady = $kind === 'order' || ($supplierId && ($kind === 'bill' || $warehouseId));

        return compact('orders', 'receipts', 'selectedOrder', 'selectedReceipt', 'supplierId', 'warehouseId', 'contextReady');
    }

    public function choices(string $kind, mixed $header, array $context, $orderLines, $receiptLines, $locations, PurchasingService $service): array
    {
        ['supplierId' => $supplierId,'warehouseId' => $warehouseId,'selectedOrder' => $selectedOrder,'selectedReceipt' => $selectedReceipt,'contextReady' => $contextReady] = $context;
        if ($kind !== 'receipt') {
            $orderLines = collect();
        }
        if (! in_array($kind, ['bill', 'return'])) {
            $receiptLines = collect();
        }
        if (! $header && $kind !== 'order' && $contextReady) {
            $orderLines = $orderLines->filter(fn ($l) => $l->order->supplier_party_role_id === $supplierId && $l->order->ship_to_warehouse_id === $warehouseId && (! $selectedOrder || $l->document_id === $selectedOrder->document_id));
            $receiptLines = $receiptLines->filter(fn ($l) => $l->receipt->supplier_party_role_id === $supplierId && (! $selectedReceipt || $l->document_id === $selectedReceipt->document_id));
            $locations = $locations->where('warehouse_id', $warehouseId)->values();
        }
        $orderCapacity = [];
        $receiptCapacity = [];
        foreach ($orderLines as $line) {
            $remaining = bcsub($line->base_quantity, $service->accepted($line->id), 6);
            $orderCapacity[$line->id] = ['base' => $remaining, 'quantity' => bcdiv($remaining, $line->conversion_factor, 6), 'closed' => $service->closed($line->document_id)];
        }
        foreach ($receiptLines as $line) {
            $returned = $service->returned($line->id);
            $matched = $service->matched($line->id);
            $receiptCapacity[$line->id] = ['returned' => $returned, 'billed' => $matched, 'billable' => bcsub(bcsub($line->accepted_base_quantity, $returned, 6), $matched, 6), 'returnable' => bcsub($line->accepted_base_quantity, $returned, 6)];
        }
        // Retain draft sources when editing, including exhausted sources for clear validation.
        if (! $header) {
            $orderLines = $orderLines->filter(fn ($l) => ! $orderCapacity[$l->id]['closed'] && bccomp($orderCapacity[$l->id]['base'], '0', 6) > 0);
            if (in_array($kind, ['bill', 'return'])) {
                $receiptLines = $receiptLines->filter(fn ($l) => bccomp($receiptCapacity[$l->id][$kind === 'bill' ? 'billable' : 'returnable'], '0', 6) > 0);
            }
        }
        $positions = StockPosition::with('lot.item.baseUnit', 'location.warehouse', 'roll')
            ->select('stock_positions.*')
            ->selectSub(DB::table('stock_movements')->selectRaw('COALESCE(SUM(quantity_delta),0)')->whereColumn('stock_position_id', 'stock_positions.id'), 'ledger_on_hand')
            ->selectSub(DB::table('stock_reservations')->selectRaw('COALESCE(SUM(quantity-fulfilled_quantity-released_quantity),0)')->whereColumn('stock_position_id', 'stock_positions.id'), 'ledger_reserved')
            ->whereHas('location', fn ($q) => $q->when(! $header, fn ($q) => $q->where('warehouse_id', $warehouseId))->where('is_active', true)->whereIn('location_type', ['internal', 'quarantine'])->whereHas('warehouse', fn ($q) => $q->where('is_active', true)))
            ->whereHas('lot', fn ($q) => $q->whereHas('item', fn ($q) => $q->where('is_active', true)->where('is_stocked', true)->whereHas('baseUnit', fn ($q) => $q->where('is_active', true))))
            ->whereIn('stock_lot_id', $receiptLines->pluck('position.stock_lot_id'))->get()
            ->filter(function ($p) use ($receiptLines) {
                $p->available_quantity = bcsub($p->ledger_on_hand, $p->ledger_reserved, 6);

                return bccomp($p->available_quantity, '0', 6) > 0 && (! $p->roll || ($p->roll->is_active && bccomp($p->available_quantity, $p->ledger_on_hand, 6) === 0)) && $receiptLines->contains(fn ($l) => $l->position->stock_lot_id === $p->stock_lot_id && $l->position->fabric_roll_id === $p->fabric_roll_id);
            });
        if (! $contextReady) {
            $orderLines = collect();
            $receiptLines = collect();
            $positions = collect();
        }

        return compact('orderLines', 'receiptLines', 'locations', 'positions', 'orderCapacity', 'receiptCapacity');
    }

    public function next(Document $document, string $kind, mixed $header, PurchasingService $service): array
    {
        $canReceive = $kind === 'order' && $document->status === 'posted' && ! $service->closed($document->id) && $header->lines->contains(fn ($l) => bccomp($l->base_quantity, $service->accepted($l->id), 6) > 0);
        $canBill = $kind === 'receipt' && $document->status === 'posted' && $header->lines->contains(fn ($l) => bccomp(bcsub(bcsub($l->accepted_base_quantity, $service->returned($l->id), 6), $service->matched($l->id), 6), '0', 6) > 0);

        return compact('canReceive', 'canBill');
    }
}

<?php

namespace App\Modules\Inventory\Services;

use App\Models\StockBalance;
use App\Models\StockCostLayer;
use App\Models\StockPosition;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InventoryInquiry
{
    public function __construct(private StockLedger $ledger) {}

    public function positions(array $filters = []): LengthAwarePaginator
    {
        return StockPosition::with(['lot.item.baseUnit', 'location.warehouse', 'roll', 'layers'])->select('stock_positions.*')
            ->when($filters['item_type'] ?? null, fn ($q, $type) => $q->whereHas('lot.item', fn ($q) => $q->where('item_type', $type)))
            ->selectSub(DB::table('stock_movements')->selectRaw('COALESCE(SUM(quantity_delta),0)')->whereColumn('stock_position_id', 'stock_positions.id'), 'ledger_quantity')
            ->selectSub(DB::table('stock_reservations')->selectRaw('COALESCE(SUM(quantity-fulfilled_quantity-released_quantity),0)')->whereColumn('stock_position_id', 'stock_positions.id'), 'ledger_reserved')
            ->selectSub(DB::table('inventory_value_entries as v')->join('stock_cost_layers as c', 'c.id', '=', 'v.stock_cost_layer_id')->selectRaw('COALESCE(SUM(v.amount_pkr),0)')->whereColumn('c.stock_position_id', 'stock_positions.id'), 'carrying_value')
            ->when($filters['q'] ?? null, fn ($q, $search) => $q->where(fn ($s) => $s->whereHas('lot', fn ($l) => $l->where('lot_number', 'like', '%'.$search.'%')->orWhereHas('item', fn ($i) => $i->where('sku', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%')))->orWhereHas('roll', fn ($r) => $r->where('roll_number', 'like', '%'.$search.'%'))))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->whereHas('location', fn ($l) => $l->where('warehouse_id', $id)))
            ->when($filters['quality_status'] ?? null, fn ($q, $status) => $q->whereHas('lot', fn ($l) => $l->where('quality_status', $status)))
            ->orderBy('id')->paginate(25)->withQueryString();
    }

    public function reconciliation(): array
    {
        $differences = $this->integrity();
        foreach (StockPosition::with('balance')->orderBy('id')->get() as $position) {
            $onHand = $this->ledger->onHand($position->id);
            $reserved = $this->ledger->reserved($position->id);
            $layers = (string) StockCostLayer::where('stock_position_id', $position->id)->sum('remaining_quantity');
            if (! $position->balance || bccomp($onHand, $position->balance->on_hand_quantity, 6) || bccomp($reserved, $position->balance->reserved_quantity, 6) || bccomp($layers, $onHand, 6)) {
                $differences[] = ['position_id' => $position->id, 'ledger' => $onHand, 'reserved' => $reserved, 'balance' => $position->balance?->on_hand_quantity, 'layers' => $layers];
            }
        }
        foreach (StockCostLayer::orderBy('id')->get() as $layer) {
            $allocated = (string) $layer->allocations()->sum('quantity_delta');
            if (bccomp($layer->remaining_quantity, $allocated, 6)) {
                $differences[] = ['layer_id' => $layer->id, 'projection' => $layer->remaining_quantity, 'allocation_history' => $allocated];
            }
        }

        return $differences;
    }

    /** Cross-ledger checks that cannot be repaired by changing a projection. */
    private function integrity(): array
    {
        $errors = [];
        $allocations = DB::table('inventory_layer_allocations')->select('stock_movement_id')->selectRaw('SUM(quantity_delta) allocated')->groupBy('stock_movement_id');
        foreach (DB::table('stock_movements as m')->leftJoinSub($allocations, 'a', fn ($j) => $j->on('a.stock_movement_id', '=', 'm.id'))->whereRaw('m.quantity_delta<>COALESCE(a.allocated,0)')->get(['m.id', 'm.quantity_delta', 'a.allocated']) as $row) {
            $errors[] = ['movement_allocation_mismatch' => (array) $row];
        }
        if (DB::table('inventory_layer_allocations as a')->join('stock_movements as m', 'm.id', '=', 'a.stock_movement_id')->join('stock_cost_layers as c', 'c.id', '=', 'a.stock_cost_layer_id')->where(fn ($q) => $q->whereColumn('m.stock_position_id', '<>', 'c.stock_position_id')->orWhereRaw('SIGN(m.quantity_delta)<>SIGN(a.quantity_delta)'))->exists()) {
            $errors[] = ['allocation_identity' => 'Movement sign or stock position does not match its layer.'];
        }
        foreach (DB::table('journal_entries as e')->join('journal_lines as l', 'l.journal_entry_id', '=', 'e.document_id')->groupBy('e.document_id', 'e.total_debit', 'e.total_credit')->havingRaw('SUM(l.debit)<>SUM(l.credit) OR SUM(l.debit)<>e.total_debit OR SUM(l.credit)<>e.total_credit')->get(['e.document_id']) as $entry) {
            $errors[] = ['journal_mismatch' => $entry->document_id];
        }
        $inventoryAccount = DB::table('posting_accounts')->where('purpose_code', 'inventory')->value('ledger_account_id');
        if ($inventoryAccount) {
            $stockValue = (string) DB::table('inventory_value_entries')->sum('amount_pkr');
            $gl = (string) DB::table('journal_lines')->where('ledger_account_id', $inventoryAccount)->sum(DB::raw('debit-credit'));
            if (bccomp($stockValue, $gl, 4)) {
                $errors[] = ['inventory_value' => $stockValue, 'inventory_control' => $gl];
            }
        }

        return $errors;
    }

    public function repair(int $actor, string $reason): void
    {
        validator(['reason' => $reason], ['reason' => 'required|string|min:5|max:1000'])->validate();
        DB::transaction(function () use ($actor, $reason) {
            InventoryGuard::company();
            InventoryGuard::actor($actor, 'inventory.reconcile');
            if ($this->integrity()) {
                InventoryGuard::fail('Immutable stock, allocation or journal histories disagree. Investigate instead of rebuilding.');
            }
            $before = $this->reconciliation();
            foreach (StockCostLayer::orderBy('id')->lockForUpdate()->get() as $layer) {
                $qty = (string) $layer->allocations()->sum('quantity_delta');
                if (bccomp($qty, '0', 6) < 0) {
                    InventoryGuard::fail('Allocation history is negative. Investigate instead of rebuilding.');
                }
                $layer->update(['remaining_quantity' => $qty]);
            }
            foreach (StockPosition::orderBy('id')->lockForUpdate()->get() as $position) {
                $sum = (string) StockCostLayer::where('stock_position_id', $position->id)->sum('remaining_quantity');
                if (bccomp($sum, $this->ledger->onHand($position->id), 6)) {
                    InventoryGuard::fail('The immutable allocation and movement histories disagree; projection repair cannot fix this.');
                }
                StockBalance::firstOrCreate(['stock_position_id' => $position->id], ['on_hand_quantity' => 0, 'reserved_quantity' => 0]);
                $this->ledger->refresh($position->id);
            }
            app(AuditLogger::class)->record('stock_balances', 'all', 'projections_rebuilt', ['differences' => $before], ['reason' => $reason, 'differences' => $this->reconciliation()], $actor);
        }, 3);
    }
}

<?php

namespace App\Modules\Inventory\Services;

use App\Models\StockBalance;
use App\Models\StockCostLayer;
use App\Models\StockPosition;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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

    /** Current filtered stock totals, grouped by ownership/vendor and by suit warehouse. */
    public function availabilitySummary(array $filters = []): array
    {
        $movements = DB::table('stock_movements')->select('stock_position_id')->selectRaw('SUM(quantity_delta) as quantity')->groupBy('stock_position_id');
        $reservations = DB::table('stock_reservations')->select('stock_position_id')->selectRaw('SUM(quantity-fulfilled_quantity-released_quantity) as quantity')->groupBy('stock_position_id');
        $values = DB::table('inventory_value_entries as v')->join('stock_cost_layers as c', 'c.id', '=', 'v.stock_cost_layer_id')->select('c.stock_position_id')->selectRaw('SUM(v.amount_pkr) as value_pkr')->groupBy('c.stock_position_id');

        $query = DB::table('stock_positions as sp')
            ->join('stock_lots as sl', 'sl.id', '=', 'sp.stock_lot_id')
            ->join('items as i', 'i.id', '=', 'sl.item_id')
            ->join('units as u', 'u.id', '=', 'i.base_unit_id')
            ->join('warehouse_locations as wl', 'wl.id', '=', 'sp.warehouse_location_id')
            ->join('warehouses as w', 'w.id', '=', 'wl.warehouse_id')
            ->leftJoin('party_roles as pr', 'pr.id', '=', 'wl.contractor_vendor_party_role_id')
            ->leftJoin('parties as p', 'p.id', '=', 'pr.party_id')
            ->leftJoinSub($movements, 'm', fn ($join) => $join->on('m.stock_position_id', '=', 'sp.id'))
            ->leftJoinSub($reservations, 'r', fn ($join) => $join->on('r.stock_position_id', '=', 'sp.id'))
            ->leftJoinSub($values, 'v', fn ($join) => $join->on('v.stock_position_id', '=', 'sp.id'))
            ->where('i.is_stocked', true)
            ->when($filters['q'] ?? null, fn ($q, $search) => $q->where(fn ($s) => $s->where('i.sku', 'like', '%'.$search.'%')->orWhere('i.name', 'like', '%'.$search.'%')->orWhere('sl.lot_number', 'like', '%'.$search.'%')->orWhereExists(fn ($roll) => $roll->selectRaw('1')->from('fabric_rolls')->whereColumn('fabric_rolls.id', 'sp.fabric_roll_id')->where('fabric_rolls.roll_number', 'like', '%'.$search.'%'))))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('w.id', $id))
            ->when($filters['quality_status'] ?? null, fn ($q, $status) => $q->where('sl.quality_status', $status));

        $rows = $query->selectRaw("wl.location_type, wl.contractor_vendor_party_role_id, COALESCE(p.trading_name, p.legal_name, 'Vendor') as vendor_name, w.id as warehouse_id, w.name as warehouse_name, i.item_type, u.code as unit, COALESCE(SUM(m.quantity),0) as on_hand, COALESCE(SUM(r.quantity),0) as reserved, COALESCE(SUM(v.value_pkr),0) as value_pkr")
            ->groupBy('wl.location_type', 'wl.contractor_vendor_party_role_id', 'p.trading_name', 'p.legal_name', 'w.id', 'w.name', 'i.item_type', 'u.code')
            ->orderBy('w.name')->orderBy('u.code')->get();

        $company = $rows->where('location_type', 'internal')->groupBy('unit')->map(fn (Collection $group, $unit) => $this->sumAvailability($group, $unit))->values();
        $vendors = $rows->where('location_type', 'contractor')->groupBy(fn ($row) => $row->contractor_vendor_party_role_id.'|'.$row->unit)->map(function (Collection $group) {
            $row = $group->first();
            return $this->sumAvailability($group, $row->unit) + ['vendor_name' => $row->vendor_name];
        })->values();
        $suits = $rows->where('location_type', 'internal')->where('item_type', 'finished')->where('unit', 'SUIT')->groupBy(fn ($row) => $row->warehouse_id.'|'.$row->unit)->map(function (Collection $group) {
            $row = $group->first();
            return $this->sumAvailability($group, $row->unit) + ['warehouse_name' => $row->warehouse_name];
        })->values();

        return compact('company', 'vendors', 'suits');
    }

    /** Filtered on-hand totals by item with their physical lot/location detail. */
    public function itemBreakdown(array $filters = []): Collection
    {
        $positions = StockPosition::with(['lot.item.baseUnit', 'location.warehouse', 'roll'])
            ->select('stock_positions.*')
            ->selectSub(DB::table('stock_movements')->selectRaw('COALESCE(SUM(quantity_delta),0)')->whereColumn('stock_position_id', 'stock_positions.id'), 'ledger_quantity')
            ->when($filters['q'] ?? null, fn ($q, $search) => $q->where(fn ($s) => $s->whereHas('lot', fn ($l) => $l->where('lot_number', 'like', '%'.$search.'%')->orWhereHas('item', fn ($i) => $i->where('sku', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%')))->orWhereHas('roll', fn ($r) => $r->where('roll_number', 'like', '%'.$search.'%'))))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->whereHas('location', fn ($l) => $l->where('warehouse_id', $id)))
            ->when($filters['quality_status'] ?? null, fn ($q, $status) => $q->whereHas('lot', fn ($l) => $l->where('quality_status', $status)))
            ->orderBy('id')->get()->filter(fn ($position) => bccomp((string) $position->ledger_quantity, '0', 6) > 0);

        return $positions->groupBy(fn ($position) => $position->lot->item_id)->map(function (Collection $lots) {
            $item = $lots->first()->lot->item;
            $total = $lots->reduce(fn ($sum, $position) => bcadd($sum, (string) $position->ledger_quantity, 6), '0');

            return ['item' => $item, 'total' => $total, 'lots' => $lots];
        })->values();
    }

    private function sumAvailability(Collection $rows, string $unit): array
    {
        $onHand = '0.000000';
        $reserved = '0.000000';
        $value = '0.0000';
        foreach ($rows as $row) {
            $onHand = bcadd($onHand, (string) $row->on_hand, 6);
            $reserved = bcadd($reserved, (string) $row->reserved, 6);
            $value = bcadd($value, (string) $row->value_pkr, 4);
        }

        return ['unit' => $unit, 'on_hand' => $onHand, 'reserved' => $reserved, 'available' => bcsub($onHand, $reserved, 6), 'value_pkr' => $value];
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

<?php

namespace App\Modules\FinishedGoods\Services;

use App\Models\InventoryValueEntry;
use App\Models\Item;
use App\Models\ProductPackSpec;
use App\Models\StockPosition;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Inventory\Services\InventoryGuard;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Support\Facades\DB;

class FinishedGoodsWorkspace
{
    public function choices(?ProductPackSpec $spec = null, string $operation = 'pack'): array
    {
        $spec?->load('item.baseUnit', 'lines.item.baseUnit');
        $ids = $spec ? ($operation === 'pack' ? $spec->lines->pluck('component_item_id')->all() : [$spec->packed_item_id]) : [];
        $positions = StockPosition::with('lot.item.baseUnit', 'location.warehouse')->whereHas('lot', fn ($q) => $q->whereIn('item_id', $ids)->when($operation === 'pack', fn ($q) => $q->where('quality_status', 'released')))->whereHas('location', fn ($q) => $q->where('is_active', true)->where('location_type', 'internal')->whereHas('warehouse', fn ($q) => $q->where('is_active', true)))->get()->map(fn ($p) => ['id' => $p->id, 'item' => $p->lot->item_id, 'available' => app(StockLedger::class)->available($p->id), 'label' => $p->lot->item->sku.' / '.$p->lot->lot_number.' / '.$p->location->warehouse->code.'/'.$p->location->code.' / available '.app(StockLedger::class)->available($p->id).' '.$p->lot->item->baseUnit->code])->filter(fn ($p) => bccomp($p['available'], '0', 6) > 0)->values();

        return ['spec' => $spec, 'operation' => $operation, 'specs' => ProductPackSpec::with('item')->where('state', 'published')->where('is_active', true)->latest('id')->get(), 'packItems' => Item::with('baseUnit')->where('item_type', 'finished')->whereHas('baseUnit', fn ($q) => $q->where('code', 'PACK'))->where('is_active', true)->orderBy('name')->get(), 'components' => Item::with('baseUnit')->where('is_active', true)->where(fn ($q) => $q->where('item_type', 'packaging')->orWhere(fn ($q) => $q->where('item_type', 'finished')->whereHas('baseUnit', fn ($q) => $q->where('code', 'SUIT'))))->orderBy('name')->get(), 'positions' => $positions, 'locations' => WarehouseLocation::with('warehouse')->where('is_active', true)->whereIn('location_type', ['internal', 'quarantine'])->whereHas('warehouse', fn ($q) => $q->where('is_active', true))->get()];
    }

    public function inventory(array $f): array
    {
        return DB::transaction(function () use ($f) {
            InventoryGuard::company();
            $s = app(StockLedger::class);
            $records = StockPosition::with('lot.item.baseUnit', 'location.warehouse', 'layers')->whereHas('lot', fn ($q) => $q->whereHas('item', fn ($q) => $q->where('item_type', 'finished'))->when($f['quality'] ?? null, fn ($q, $v) => $q->where('quality_status', $v))->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('lot_number', 'like', '%'.$v.'%')->orWhereHas('item', fn ($q) => $q->where('sku', 'like', '%'.$v.'%')->orWhere('name', 'like', '%'.$v.'%')))))->when($f['warehouse'] ?? null, fn ($q, $v) => $q->whereHas('location', fn ($q) => $q->where('warehouse_id', $v)))->latest('id')->paginate(20)->withQueryString();
            $values = [];
            foreach ($records as $p) {
                $values[$p->id] = ['on_hand' => $s->onHand($p->id), 'available' => $s->available($p->id), 'value' => bcadd((string) InventoryValueEntry::whereIn('stock_cost_layer_id', $p->layers->pluck('id'))->sum('amount_pkr'), '0', 4)];
            }

            return compact('records', 'values') + ['warehouses' => Warehouse::where('is_active', true)->orderBy('code')->get()];
        });
    }
}

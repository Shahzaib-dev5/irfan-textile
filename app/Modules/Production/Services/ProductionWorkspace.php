<?php

namespace App\Modules\Production\Services;

use App\Models\BillOfMaterial;
use App\Models\Item;
use App\Models\JournalLine;
use App\Models\ManufacturingBatch;
use App\Models\PostingAccount;
use App\Models\ProductVariant;
use App\Models\StockPosition;
use App\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockLedger;

class ProductionWorkspace
{
    public function batches()
    {
        return ManufacturingBatch::with('document', 'bom.variant.item', 'bom.variant.design', 'bom.variant.color')->whereHas('document', fn ($q) => $q->where('status', 'posted'))->whereDoesntHave('reports', fn ($q) => $q->where('is_final', true)->whereHas('document', fn ($q) => $q->where('status', 'posted')))->latest('document_id')->get();
    }

    public function choices(): array
    {
        $positions = StockPosition::with('lot.item.baseUnit', 'roll', 'location.warehouse')->whereHas('location', fn ($q) => $q->where('is_active', true)->where('location_type', 'internal')->whereHas('warehouse', fn ($q) => $q->where('is_active', true)))->whereHas('lot', fn ($q) => $q->where('quality_status', 'released')->whereHas('item', fn ($q) => $q->where('is_active', true)->where('is_stocked', true)))->whereHas('balance', fn ($q) => $q->whereRaw('on_hand_quantity>reserved_quantity'))->orderBy('id')->get()->map(function ($p) {
            $available = app(StockLedger::class)->available($p->id);

            return ['id' => $p->id, 'item' => $p->lot->item_id, 'unit' => $p->lot->item->baseUnit->code, 'available' => $available, 'label' => $p->lot->item->sku.' / '.$p->lot->lot_number.' / '.($p->roll?->roll_number ?? 'No roll').' / '.$p->location->warehouse->code.'/'.$p->location->code.' / Available '.$available.' '.$p->lot->item->baseUnit->code];
        })->filter(fn ($p) => bccomp($p['available'], '0', 6) > 0)->values();
        $accounts = PostingAccount::whereIn('purpose_code', ['production_labor', 'production_overhead', 'production_conversion'])->pluck('ledger_account_id');
        $costSources = JournalLine::with('document', 'account')->whereIn('ledger_account_id', $accounts)->where('debit', '>', 0)->whereHas('document', fn ($q) => $q->where('status', 'posted'))->orderBy('id')->get();

        return ['boms' => BillOfMaterial::with('variant.item', 'variant.design', 'variant.color')->where('state', 'published')->where('is_active', true)->latest('id')->get(), 'variants' => ProductVariant::with('item', 'design', 'color')->where('is_active', true)->get(), 'items' => Item::with('baseUnit')->where('is_active', true)->where('is_stocked', true)->orderBy('name')->get(), 'locations' => WarehouseLocation::with('warehouse')->where('is_active', true)->whereIn('location_type', ['internal', 'quarantine'])->whereHas('warehouse', fn ($q) => $q->where('is_active', true))->get(), 'positions' => $positions, 'costSources' => $costSources];
    }
}

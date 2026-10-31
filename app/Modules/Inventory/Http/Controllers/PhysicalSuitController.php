<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\StockPosition;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Inventory\Http\Requests\PhysicalSuitRequest;
use App\Modules\Inventory\Services\InventoryInquiry;
use App\Modules\Inventory\Services\PhysicalSuitStock;
use Illuminate\Http\Request;

class PhysicalSuitController extends Controller
{
    public function index(Request $request, InventoryInquiry $inquiry)
    {
        $fabric = $request->routeIs('physical-stock.fabric');
        $positions = $inquiry->positions($request->only('q', 'warehouse_id', 'quality_status') + ['item_type' => $fabric ? 'processed_fabric' : 'finished']);
        $variants = ProductVariant::with('design', 'color')->get()->keyBy('item_id');
        $warehouses = Warehouse::orderBy('name')->get();

        return view('module.inventory::physical.index', compact('positions', 'variants', 'warehouses', 'fabric'));
    }

    public function create()
    {
        $variants = ProductVariant::with('item', 'design', 'color')->where('is_active', true)->whereHas('item', fn ($q) => $q->where('is_active', true)->whereHas('baseUnit', fn ($q) => $q->where('code', 'SUIT')))->get();
        $locations = WarehouseLocation::with('warehouse')->where('is_active', true)->whereIn('location_type', ['internal', 'quarantine'])->whereHas('warehouse', fn ($q) => $q->where('is_active', true))->get();
        $materials = StockPosition::with('lot.item.baseUnit', 'location.warehouse')->whereHas('lot', fn ($q) => $q->where('quality_status', 'released')->whereHas('item', fn ($q) => $q->where('is_active', true)->where('item_type', '<>', 'finished')))->whereHas('location', fn ($q) => $q->where('location_type', 'internal'))->whereHas('balance', fn ($q) => $q->where('on_hand_quantity', '>', 0))->get();

        return view('module.inventory::physical.create', compact('variants', 'locations', 'materials'));
    }

    public function store(PhysicalSuitRequest $request, PhysicalSuitStock $service)
    {
        $doc = $service->confirm($request->user()->id, $request->validated());

        return redirect()->route('physical-stock.index')->with('status', 'Physical suit stock confirmed: '.$doc->number.'. Stock and cost history updated.');
    }
}

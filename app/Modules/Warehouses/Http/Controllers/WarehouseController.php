<?php

namespace App\Modules\Warehouses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PartyRole;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Warehouses\Http\Requests\SaveWarehouseRequest;
use App\Modules\Warehouses\Services\WarehouseService;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Warehouse::class);
        $warehouses = Warehouse::with('locations')->when($request->filled('q'), fn ($q) => $q->where(fn ($s) => $s->where('name', 'like', '%'.$request->string('q').'%')->orWhere('code', 'like', '%'.$request->string('q').'%')))->orderBy('code')->paginate(15)->withQueryString();

        return view('module.warehouses::index', compact('warehouses'));
    }

    public function form(Request $request, ?int $record = null)
    {
        $this->authorize('create', Warehouse::class);
        $location = $request->boolean('location');
        $model = $location ? WarehouseLocation::class : Warehouse::class;
        $entry = $record ? $model::findOrFail($record) : new $model(['is_active' => true, 'location_type' => 'internal', 'warehouse_id' => $request->integer('warehouse_id') ?: null]);
        $warehouses = Warehouse::orderBy('name')->get();
        $vendors = PartyRole::with('party')->whereHas('vendorProfile')->where('is_active', true)->get();

        return view('module.warehouses::form', compact('entry', 'location', 'warehouses', 'vendors'));
    }

    public function save(SaveWarehouseRequest $request, WarehouseService $service, ?int $record = null)
    {
        $this->authorize('create', Warehouse::class);
        $service->save($request->user()->id, $request->validated(), $record, $request->boolean('location'));

        return redirect()->route('warehouses.index')->with('status', 'Warehouse setup saved.');
    }
}

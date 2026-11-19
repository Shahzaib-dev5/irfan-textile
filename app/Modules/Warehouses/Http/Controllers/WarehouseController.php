<?php

namespace App\Modules\Warehouses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Modules\Warehouses\Http\Requests\SaveWarehouseRequest;
use App\Modules\Warehouses\Services\WarehouseService;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Warehouse::class);
        $warehouses = Warehouse::orderBy('name')->paginate(20);

        return view('module.warehouses::index', compact('warehouses'));
    }

    public function form(Request $request, ?int $record = null)
    {
        $this->authorize('create', Warehouse::class);
        abort_if($request->boolean('location'), 404);
        $entry = $record ? Warehouse::findOrFail($record) : new Warehouse(['is_active' => true]);

        return view('module.warehouses::form', compact('entry'));
    }

    public function save(SaveWarehouseRequest $request, WarehouseService $service, ?int $record = null)
    {
        $this->authorize('create', Warehouse::class);
        $service->save($request->user()->id, $request->validated(), $record, $request->boolean('location'));

        return redirect()->route('warehouses.index')->with('status', 'Warehouse setup saved.');
    }
}

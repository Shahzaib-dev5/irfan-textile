<?php

namespace App\Modules\Warehouses\Services;

use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Inventory\Services\InventoryGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Location keys are internal ledger details, not operator-managed storage. */
class MainWarehouse
{
    public function warehouse(): Warehouse
    {
        return Warehouse::where('code', 'MAIN')->where('is_active', true)->first()
            ?? Warehouse::where('is_active', true)->orderBy('id')->first()
            ?? throw ValidationException::withMessages(['warehouse' => 'Set up an active warehouse before recording stock.']);
    }

    public function location(): WarehouseLocation
    {
        return $this->warehouse()->locations()->where('code', 'MAIN')->where('location_type', 'internal')->where('is_active', true)->firstOrFail();
    }

    public function setup(int $actor, ?string $address = null): Warehouse
    {
        return DB::transaction(function () use ($actor, $address) {
            InventoryGuard::company();
            InventoryGuard::actor($actor, 'warehouses.manage');
            $warehouse = Warehouse::updateOrCreate(['code' => 'MAIN'], ['name' => 'Main Warehouse', 'address' => $address, 'is_active' => true]);
            $warehouse->locations()->firstOrCreate(['code' => 'MAIN'], ['name' => 'Main Warehouse', 'location_type' => 'internal', 'is_active' => true]);

            return $warehouse;
        }, 3);
    }

    public function custody(int $vendor): WarehouseLocation
    {
        return $this->warehouse()->locations()->firstOrCreate(['code' => 'VENDOR-'.$vendor], [
            'name' => 'Vendor-held fabric', 'location_type' => 'contractor',
            'contractor_vendor_party_role_id' => $vendor, 'is_active' => true,
        ]);
    }
}

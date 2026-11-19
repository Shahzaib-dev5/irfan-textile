<?php

namespace App\Modules\Warehouses\Services;

use App\Models\Warehouse;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as Guard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WarehouseService
{
    public function save(int $actor, array $input, ?int $id = null, bool $location = false): Warehouse
    {
        return DB::transaction(function () use ($actor, $input, $id, $location) {
            Guard::company();
            Guard::actor($actor, 'warehouses.manage');
            if ($location) {
                Guard::fail('Manage warehouses only. Internal storage is assigned automatically.');
            }
            $data = validator($input, [
                'code' => ['required', 'string', 'max:30', Rule::unique('warehouses')->ignore($id)],
                'name' => 'required|string|max:120', 'address' => 'nullable|string|max:500',
                'is_active' => 'required|boolean',
            ])->validate();
            $record = $id ? Warehouse::lockForUpdate()->findOrFail($id) : new Warehouse;
            $before = $record->exists ? $record->toArray() : null;
            if ($record->exists && ! $data['is_active']) {
                $positions = DB::table('stock_positions as p')->join('warehouse_locations as l', 'l.id', '=', 'p.warehouse_location_id')->where('l.warehouse_id', $record->id)->pluck('p.id');
                if (DB::table('stock_movements')->whereIn('stock_position_id', $positions)->groupBy('stock_position_id')->havingRaw('SUM(quantity_delta) <> 0')->exists()) {
                    Guard::fail('Move all stock before deactivating this warehouse.');
                }
            }
            $record->fill($data)->save();
            $record->locations()->firstOrCreate(['code' => 'MAIN'], ['name' => $record->name, 'location_type' => 'internal', 'is_active' => true]);
            app(AuditLogger::class)->record('warehouses', $record->id, $before ? 'updated' : 'created', $before, $record->toArray(), $actor);

            return $record;
        }, 3);
    }
}

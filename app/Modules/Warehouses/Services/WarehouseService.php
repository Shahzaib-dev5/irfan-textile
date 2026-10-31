<?php

namespace App\Modules\Warehouses\Services;

use App\Models\PartyRole;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as Guard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WarehouseService
{
    public function save(int $actor, array $input, ?int $id = null, bool $location = false): Warehouse|WarehouseLocation
    {
        return DB::transaction(function () use ($actor, $input, $id, $location) {
            Guard::company();
            Guard::actor($actor, 'warehouses.manage');
            $table = $location ? 'warehouse_locations' : 'warehouses';
            $class = $location ? WarehouseLocation::class : Warehouse::class;
            $rules = ['code' => ['required', 'string', 'max:30', Rule::unique($table)->ignore($id)->when($location, fn ($r) => $r->where('warehouse_id', $input['warehouse_id'] ?? 0))], 'name' => 'required|string|max:120', 'is_active' => 'required|boolean'];
            if ($location) {
                $rules += ['warehouse_id' => 'required|integer|exists:warehouses,id', 'location_type' => 'required|in:internal,contractor,transit,quarantine', 'contractor_vendor_party_role_id' => 'nullable|integer|exists:vendor_profiles,party_role_id'];
            } else {
                $rules['address'] = 'nullable|string|max:500';
            }
            $data = validator($input, $rules)->validate();
            $record = $id ? $class::lockForUpdate()->findOrFail($id) : new $class;
            $before = $record->exists ? $record->toArray() : null;
            if ($location) {
                $vendor = $data['contractor_vendor_party_role_id'] ?? null;
                if (($data['location_type'] === 'contractor') !== (bool) $vendor) {
                    Guard::fail('Contractor locations require one vendor role; other locations cannot name a contractor.');
                }
                if ($vendor && ! PartyRole::whereKey($vendor)->where('is_active', true)->whereHas('party', fn ($q) => $q->where('is_active', true))->exists()) {
                    Guard::fail('Choose an active vendor party.');
                }
                if ($data['is_active'] && ! Warehouse::findOrFail($data['warehouse_id'])->is_active) {
                    Guard::fail('Activate the parent warehouse first.');
                }
                if ($record->exists && $record->positions()->exists()) {
                    foreach (['warehouse_id', 'location_type', 'contractor_vendor_party_role_id'] as $field) {
                        if (($data[$field] ?? null) != $record->$field) {
                            Guard::fail('A used location cannot change warehouse, type or contractor. Create a new location.');
                        }
                    }
                }
            }
            if ($record->exists && ! $data['is_active']) {
                $positions = DB::table('stock_positions as p')->join('warehouse_locations as l', 'l.id', '=', 'p.warehouse_location_id')->where($location ? 'l.id' : 'l.warehouse_id', $id)->pluck('p.id');
                if (DB::table('stock_movements')->whereIn('stock_position_id', $positions)->groupBy('stock_position_id')->havingRaw('SUM(quantity_delta) <> 0')->exists()) {
                    Guard::fail('Move all stock before deactivating its storage.');
                }
            }
            $record->fill($data)->save();
            app(AuditLogger::class)->record($table, $record->id, $before ? 'updated' : 'created', $before, $record->fresh()->toArray(), $actor);

            return $record->fresh();
        }, 3);
    }
}

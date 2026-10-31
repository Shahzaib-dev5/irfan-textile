<?php

namespace Database\Factories;

use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarehouseLocationFactory extends Factory
{
    protected $model = WarehouseLocation::class;

    public function definition(): array
    {
        return ['warehouse_id' => Warehouse::factory(), 'code' => strtoupper(fake()->unique()->bothify('BIN-###??')), 'name' => 'Grey fabric storage', 'location_type' => 'internal', 'is_active' => true];
    }
}

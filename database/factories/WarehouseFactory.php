<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return ['code' => strtoupper(fake()->unique()->bothify('WH-####??')), 'name' => fake()->city().' warehouse', 'is_active' => true];
    }
}

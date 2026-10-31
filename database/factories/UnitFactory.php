<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Unit> */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        return ['code' => 'U_'.strtoupper(fake()->unique()->bothify('??????')), 'name' => fake()->word().' unit', 'dimension' => 'count', 'decimal_places' => 0, 'is_active' => true];
    }
}

<?php

namespace Database\Factories;

use App\Models\Design;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Design> */
class DesignFactory extends Factory
{
    protected $model = Design::class;

    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('DSN-########'), 'name' => fake()->words(3, true), 'season' => 'Summer 2027', 'description' => null, 'is_active' => true];
    }
}

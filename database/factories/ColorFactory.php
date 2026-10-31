<?php

namespace Database\Factories;

use App\Models\Color;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Color> */
class ColorFactory extends Factory
{
    protected $model = Color::class;

    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('CLR-########'), 'name' => fake()->colorName(), 'hex_code' => fake()->hexColor(), 'is_active' => true];
    }
}

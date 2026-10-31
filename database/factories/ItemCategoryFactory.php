<?php

namespace Database\Factories;

use App\Models\ItemCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ItemCategory> */
class ItemCategoryFactory extends Factory
{
    protected $model = ItemCategory::class;

    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('CAT-########'), 'name' => fake()->words(2, true), 'parent_id' => null, 'is_active' => true];
    }
}

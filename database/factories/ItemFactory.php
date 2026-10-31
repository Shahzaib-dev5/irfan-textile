<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Item> */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return ['category_id' => ItemCategory::factory(), 'sku' => fake()->unique()->bothify('SKU-########'), 'name' => fake()->words(3, true), 'item_type' => 'trim', 'base_unit_id' => Unit::factory(), 'is_stocked' => true, 'track_rolls' => false, 'is_active' => true, 'description' => null];
    }

    public function grey(): static
    {
        return $this->state(fn () => ['item_type' => 'grey_fabric', 'base_unit_id' => Unit::query()->firstOrCreate(['code' => 'M'], ['name' => 'Meter', 'dimension' => 'length', 'decimal_places' => 3, 'is_active' => true])->id, 'track_rolls' => true]);
    }

    public function finished(): static
    {
        return $this->state(fn () => ['item_type' => 'finished', 'base_unit_id' => Unit::query()->firstOrCreate(['code' => 'SUIT'], ['name' => 'Suit', 'dimension' => 'count', 'decimal_places' => 0, 'is_active' => true])->id]);
    }
}

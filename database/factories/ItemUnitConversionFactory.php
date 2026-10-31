<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemUnitConversion;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ItemUnitConversion> */
class ItemUnitConversionFactory extends Factory
{
    protected $model = ItemUnitConversion::class;

    public function definition(): array
    {
        return ['item_id' => Item::factory(), 'unit_id' => Unit::factory(), 'factor_to_base' => '12.0000000000'];
    }
}

<?php

namespace Database\Factories;

use App\Models\Color;
use App\Models\Design;
use App\Models\Item;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariant> */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return ['item_id' => Item::factory()->finished(), 'design_id' => Design::factory(), 'color_id' => Color::factory(), 'configuration_code' => 'THREE_PIECE', 'piece_count' => 3, 'is_active' => true];
    }
}

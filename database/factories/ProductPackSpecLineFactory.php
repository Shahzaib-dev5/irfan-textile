<?php

namespace Database\Factories;

use App\Models\ProductPackSpec;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductPackSpecLineFactory extends Factory
{
    public function definition(): array
    {
        return ['product_pack_spec_id' => ProductPackSpec::factory(), 'line_no' => 1, 'component_item_id' => fn () => ProductVariant::factory()->create()->item_id, 'component_kind' => 'saleable_content', 'quantity_per_pack' => '1'];
    }
}

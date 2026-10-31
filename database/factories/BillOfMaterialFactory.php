<?php

namespace Database\Factories;

use App\Models\BillOfMaterial;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

class BillOfMaterialFactory extends Factory
{
    protected $model = BillOfMaterial::class;

    public function definition(): array
    {
        return ['product_variant_id' => ProductVariant::factory(), 'version_number' => 1, 'output_quantity' => '1', 'state' => 'draft', 'is_active' => true];
    }
}

<?php

namespace Database\Factories;

use App\Models\FabricSpecification;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FabricSpecification> */
class FabricSpecificationFactory extends Factory
{
    protected $model = FabricSpecification::class;

    public function definition(): array
    {
        return ['item_id' => Item::factory()->grey(), 'width_mm' => '1400.000', 'gsm' => '120.000', 'composition' => '100% cotton', 'weave' => 'Plain'];
    }
}

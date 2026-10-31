<?php

namespace Database\Factories;

use App\Models\BillOfMaterial;
use App\Models\BillOfMaterialLine;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

class BillOfMaterialLineFactory extends Factory
{
    protected $model = BillOfMaterialLine::class;

    public function definition(): array
    {
        return ['bill_of_material_id' => BillOfMaterial::factory(), 'line_no' => 1, 'component_item_id' => Item::factory(), 'component_name' => 'Suit fabric', 'net_quantity' => '6', 'planned_wastage_fraction' => '0'];
    }
}

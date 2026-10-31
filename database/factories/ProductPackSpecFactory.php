<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductPackSpecFactory extends Factory
{
    public function definition(): array
    {
        return ['packed_item_id' => Item::factory()->state(['item_type' => 'finished', 'base_unit_id' => Unit::where('code', 'PACK')->value('id'), 'is_stocked' => true, 'track_rolls' => false]), 'version_number' => 1, 'description' => 'Draft pack specification', 'state' => 'draft', 'is_active' => true];
    }
}

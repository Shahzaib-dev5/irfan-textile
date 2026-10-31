<?php

namespace Database\Seeders;

use App\Models\ItemCategory;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class CatalogConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['M', 'Meter', 'length', 3], ['CM', 'Centimeter', 'length', 3], ['YD', 'Yard', 'length', 3], ['KG', 'Kilogram', 'mass', 3], ['PCS', 'Piece', 'count', 0], ['SUIT', 'Complete suit', 'count', 0], ['SET', 'Whole-product set', 'count', 0], ['DOZEN', 'Dozen', 'count', 0], ['SVC', 'Service', 'service', 0]] as [$code,$name,$dimension,$precision]) {
            Unit::query()->firstOrCreate(['code' => $code], ['name' => $name, 'dimension' => $dimension, 'decimal_places' => $precision, 'is_active' => true]);
        }
        foreach (['grey-fabric' => 'Raw grey / kora fabric', 'processed-fabric' => 'Processed fabric', 'materials' => 'Materials and trims', 'consumables' => 'Consumables', 'packaging' => 'Packing materials', 'finished' => 'Finished suits', 'scrap' => 'Scrap', 'services' => 'Nonstock services'] as $code => $name) {
            ItemCategory::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }
    }
}

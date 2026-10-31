<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class FinishedGoodsConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $unit = Unit::firstOrCreate(['code' => 'PACK'], ['name' => 'Packed assortment', 'dimension' => 'count', 'decimal_places' => 0, 'is_active' => true]);
        if ($unit->dimension !== 'count' || $unit->decimal_places !== 0) {
            throw new \LogicException('Existing PACK unit must represent whole-count packs. Review its configuration first.');
        }
        DocumentType::firstOrCreate(['code' => 'finished_goods_packing'], ['name' => 'Packing / recovery conversion', 'module_code' => 'finished-goods', 'prefix' => 'PK']);
    }
}

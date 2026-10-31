<?php

namespace Database\Seeders;

use App\Models\ProcessingService;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class ProcessingFabricSetupSeeder extends Seeder
{
    public function run(): void
    {
        ProcessingService::firstOrCreate(['code' => 'embroidery-suit'], [
            'name' => 'Embroidery (per suit)', 'billing_unit_id' => Unit::where('code', 'SUIT')->firstOrFail()->id,
            'description' => 'Charge the inspected billable suit quantity; returned laats may contain individual components.', 'is_active' => true,
        ]);
    }
}

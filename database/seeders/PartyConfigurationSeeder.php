<?php

namespace Database\Seeders;

use App\Models\PartyRoleType;
use App\Models\PaymentTerm;
use App\Models\ProcessingService;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class PartyConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['M', 'Meter', 'length', 3], ['KG', 'Kilogram', 'mass', 3], ['PCS', 'Piece', 'count', 0],
            ['SUIT', 'Suit', 'count', 0], ['ROLL', 'Roll', 'count', 0], ['PACK', 'Pack', 'count', 0],
        ] as [$code, $name, $dimension, $places]) {
            Unit::query()->firstOrCreate(['code' => $code], ['name' => $name, 'dimension' => $dimension, 'decimal_places' => $places, 'is_active' => true]);
        }
        foreach ([['supplier', 'Supplier'], ['vendor', 'Processing vendor'], ['customer', 'Customer']] as [$code, $name]) {
            PartyRoleType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }
        foreach ([['immediate', 'Due immediately', 0], ['net-7', '7 days', 7], ['net-15', '15 days', 15], ['net-30', '30 days', 30]] as [$code, $name, $days]) {
            PaymentTerm::query()->firstOrCreate(['code' => $code], ['name' => $name, 'due_days' => $days, 'is_active' => true]);
        }
        foreach ([
            ['dyeing', 'Dyeing'], ['dan', 'Dan'], ['combined-dan-dyeing', 'Combined Dan/Dyeing'],
            ['embroidery', 'Embroidery'], ['pressing', 'Pressing'], ['calendaring', 'Calendaring'], ['finishing', 'Finishing'],
        ] as [$code, $name]) {
            $unit = Unit::query()->where('code', $code === 'embroidery' ? 'PCS' : 'M')->firstOrFail();
            ProcessingService::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'billing_unit_id' => $unit->id,
                'description' => $code === 'embroidery'
                    ? 'Embroidery capability; default billing basis is per piece or set.'
                    : 'Outsourced fabric processing capability; default billing basis is per meter.',
                'is_active' => true,
            ]);
        }
    }
}

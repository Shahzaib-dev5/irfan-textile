<?php

namespace Database\Factories;

use App\Models\ProcessingRouteVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProcessingRouteVersionFactory extends Factory
{
    protected $model = ProcessingRouteVersion::class;

    public function definition(): array
    {
        return ['route_code' => strtoupper(fake()->unique()->bothify('ROUTE-????##')), 'version_number' => 1, 'name' => 'Fabric finishing route', 'state' => 'draft', 'is_active' => true];
    }
}

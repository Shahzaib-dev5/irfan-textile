<?php

namespace Database\Factories;

use App\Models\FinancialYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinancialYear> */
class FinancialYearFactory extends Factory
{
    protected $model = FinancialYear::class;

    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(2020, 2040);

        return ['code' => 'FY'.$year, 'starts_on' => $year.'-07-01', 'ends_on' => ($year + 1).'-06-30', 'is_closed' => false];
    }
}

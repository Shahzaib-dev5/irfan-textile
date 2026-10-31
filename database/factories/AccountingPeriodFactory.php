<?php

namespace Database\Factories;

use App\Models\AccountingPeriod;
use App\Models\FinancialYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AccountingPeriod> */
class AccountingPeriodFactory extends Factory
{
    protected $model = AccountingPeriod::class;

    public function definition(): array
    {
        $year = FinancialYear::factory();

        return ['financial_year_id' => $year, 'code' => 'P01', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'status' => 'open'];
    }
}

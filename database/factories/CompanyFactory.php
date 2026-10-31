<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return ['id' => 1, 'legal_name' => fake()->company(), 'functional_currency' => 'PKR', 'timezone' => 'Asia/Karachi', 'fiscal_year_start_month' => 7];
    }
}

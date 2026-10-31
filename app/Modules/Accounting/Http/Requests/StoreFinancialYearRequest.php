<?php

namespace App\Modules\Accounting\Http\Requests;

use App\Models\FinancialYear;
use Illuminate\Foundation\Http\FormRequest;

class StoreFinancialYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FinancialYear::class);
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:20', 'alpha_dash', 'unique:financial_years,code'],
            'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on']];
    }
}

<?php

namespace App\Modules\Core\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Company::query()->find(1) ?? new Company(['id' => 1]));
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:200'],
            'trading_name' => ['nullable', 'string', 'max:200'],
            'ntn' => ['nullable', 'string', 'max:30'],
            'sales_tax_registration' => ['nullable', 'string', 'max:40'],
            'address_text' => ['nullable', 'string', 'max:10000'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:254'],
            'timezone' => ['required', Rule::in(timezone_identifiers_list())],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }
}

<?php

namespace App\Modules\Parties\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublishVendorRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermissionTo('processing.vendor.manage');
    }

    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'billing_unit_id' => ['required', 'integer', 'exists:units,id'],
            'unit_rate' => ['required', 'numeric', 'min:0', 'max:999999999999.99999999'],
            'contract_reference' => ['nullable', 'string', 'max:100'],
            'rate_reason' => ['required', 'string', 'max:500', Rule::notIn([''])],
        ];
    }
}

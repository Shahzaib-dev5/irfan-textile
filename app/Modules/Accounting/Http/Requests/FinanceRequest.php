<?php

namespace App\Modules\Accounting\Http\Requests;

use App\Modules\Accounting\Services\FinanceService;
use Illuminate\Foundation\Http\FormRequest;

class FinanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = match ($this->input('kind')) {
            'opening' => 'accounting.finance.opening','reversal' => 'accounting.finance.reverse',default => 'accounting.finance.post'
        };

        return $this->user()?->hasPermissionTo($permission) ?? false;
    }

    public function rules(): array
    {
        return FinanceService::rules();
    }
}

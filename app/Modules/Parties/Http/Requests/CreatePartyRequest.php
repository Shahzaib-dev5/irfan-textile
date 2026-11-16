<?php

namespace App\Modules\Parties\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreatePartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! in_array($this->input('opening.direction'), ['receive', 'pay'], true)
            || (bool) $this->user()?->hasPermissionTo('accounting.finance.opening');
    }

    public function rules(): array
    {
        $enabled = in_array($this->input('opening.direction'), ['receive', 'pay'], true);

        return [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'distinct', Rule::in(['supplier', 'vendor', 'customer'])],
            'opening' => ['nullable', 'array:direction,role,amount,reference,document_date,due_on,idempotency_key'],
            'opening.direction' => ['nullable', Rule::in(['none', 'receive', 'pay'])],
            'opening.role' => [Rule::excludeIf(! $enabled), 'required', Rule::in(['supplier', 'vendor', 'customer'])],
            'opening.amount' => [Rule::excludeIf(! $enabled), 'required', 'decimal:0,4', 'gt:0', 'lt:10000000000000000'],
            'opening.reference' => [Rule::excludeIf(! $enabled), 'required', 'string', 'max:100'],
            'opening.document_date' => [Rule::excludeIf(! $enabled), 'required', 'date_format:Y-m-d'],
            'opening.due_on' => [Rule::excludeIf(! $enabled), 'required', 'date_format:Y-m-d'],
            'opening.idempotency_key' => [Rule::excludeIf(! $enabled), 'required', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! in_array($this->input('opening.direction'), ['receive', 'pay'], true)) {
                return;
            }
            $role = $this->input('opening.role');
            if (! in_array($role, (array) $this->input('roles', []), true)) {
                $validator->errors()->add('opening.role', 'Choose a business role selected for this party.');
            }
            if ($this->input('opening.direction') === 'receive' ? $role !== 'customer' : ! in_array($role, ['supplier', 'vendor'], true)) {
                $validator->errors()->add('opening.role', 'Receive money uses the Customer role; pay money uses the Supplier or Vendor role.');
            }
            if (is_string($this->input('opening.reference')) && trim($this->input('opening.reference')) === '') {
                $validator->errors()->add('opening.reference', 'Enter the old invoice number or verified balance schedule reference.');
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'opening.role' => 'old balance business role', 'opening.amount' => 'old unpaid amount',
            'opening.reference' => 'old invoice / schedule reference', 'opening.document_date' => 'opening date',
            'opening.due_on' => 'original due date', 'opening.idempotency_key' => 'opening request key',
        ];
    }
}

<?php

namespace App\Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePurchaseRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($kind = $this->route('workspace_kind')) {
            $this->merge(['kind' => $kind]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('purchasing.documents.manage') ?? false;
    }

    public function rules(): array
    {
        return ['intent' => 'sometimes|in:save,confirm', 'kind' => 'required|in:order,receipt,return,bill', 'document_date' => 'required|date_format:Y-m-d', 'idempotency_key' => 'required|uuid', 'lock_version' => 'sometimes|integer|min:0', 'supplier_party_role_id' => 'required|integer|exists:party_roles,id', 'warehouse_id' => 'required_unless:kind,bill|nullable|integer|exists:warehouses,id', 'payment_term_id' => 'nullable|integer|exists:payment_terms,id', 'expected_on' => 'nullable|date_format:Y-m-d', 'supplier_reference' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:5000', 'supplier_challan_number' => 'nullable|string|max:100', 'supplier_challan_date' => 'nullable|date_format:Y-m-d', 'received_at' => 'nullable|date', 'return_reference' => 'nullable|string|max:100', 'reason' => 'required_if:kind,return|nullable|string|min:5|max:500', 'supplier_invoice_number' => 'required_if:kind,bill|nullable|string|max:100', 'supplier_invoice_date' => 'required_if:kind,bill|nullable|date_format:Y-m-d', 'due_on' => 'required_if:kind,bill|nullable|date_format:Y-m-d|after_or_equal:supplier_invoice_date', 'lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array', 'tax_amount' => 'prohibited', 'rounding_amount' => 'prohibited', 'gross_amount' => 'prohibited'];
    }
}

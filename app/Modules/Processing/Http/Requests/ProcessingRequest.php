<?php

namespace App\Modules\Processing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessingRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($kind = $this->route('workspace_kind')) {
            $this->merge(['kind' => $kind]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('processing.documents.manage') ?? false;
    }

    public function rules(): array
    {
        return ['manufacturing_batch_document_id' => 'nullable|integer|exists:manufacturing_batches,document_id', 'intent' => 'sometimes|in:save,confirm', 'kind' => 'required|in:job,dispatch,receipt,bill', 'document_date' => 'required|date_format:Y-m-d', 'idempotency_key' => 'required|uuid', 'processing_route_version_id' => 'nullable|integer', 'planned_input_quantity' => 'nullable|numeric', 'required_by' => 'nullable|date_format:Y-m-d', 'instructions' => 'nullable|string|max:2000', 'processing_job_document_id' => 'nullable|integer', 'vendor_party_role_id' => 'nullable|integer', 'contractor_reference' => 'nullable|string|max:100', 'remarks' => 'nullable|string|max:1000', 'supplier_invoice_number' => 'nullable|string|max:100', 'supplier_invoice_date' => 'nullable|date_format:Y-m-d', 'due_on' => 'nullable|date_format:Y-m-d', 'lines' => 'required|array|min:1|max:100', 'lines.*' => 'array'];
    }
}

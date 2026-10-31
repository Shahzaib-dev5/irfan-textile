<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($kind = $this->route('workspace_kind')) {
            $this->merge(['kind' => $kind]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('production.manage') ?? false;
    }

    public function rules(): array
    {
        return ['intent' => 'sometimes|in:save,confirm', 'kind' => 'required|in:batch,report', 'document_date' => 'required|date_format:Y-m-d', 'idempotency_key' => 'required|uuid', 'bill_of_material_id' => 'nullable|integer', 'planned_output_quantity' => 'nullable|numeric', 'planned_start_on' => 'nullable|date_format:Y-m-d', 'planned_finish_on' => 'nullable|date_format:Y-m-d', 'instructions' => 'nullable|string|max:2000', 'manufacturing_batch_document_id' => 'nullable|integer', 'is_final' => 'sometimes|boolean', 'report_note' => 'nullable|string|max:1000', 'materials' => 'sometimes|array', 'materials.*' => 'array', 'outputs' => 'sometimes|array', 'outputs.*' => 'array', 'wastage' => 'sometimes|array', 'wastage.*' => 'array', 'costs' => 'sometimes|array', 'costs.*' => 'array'];
    }
}

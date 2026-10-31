<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('inventory.manage') ?? false;
    }

    public function rules(): array
    {
        return ['kind' => 'required|in:adjustment,transfer', 'idempotency_key' => 'required|uuid', 'document_date' => 'required|date_format:Y-m-d', 'lock_version' => 'sometimes|integer|min:0', 'explanation' => 'required|string|min:5|max:1000', 'reference' => 'nullable|string|max:120', 'external_reference' => 'nullable|string|max:100', 'adjustment_reason' => 'required_if:kind,adjustment|in:opening,count,damage,write_off', 'related_transfer_document_id' => 'nullable|integer', 'lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array'];
    }
}

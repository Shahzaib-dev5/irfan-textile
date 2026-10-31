<?php

namespace App\Modules\FinishedGoods\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('finished-goods.manage') ?? false;
    }

    public function rules(): array
    {
        return ['intent' => 'sometimes|in:save,confirm', 'document_date' => 'required|date_format:Y-m-d', 'idempotency_key' => 'required|uuid', 'product_pack_spec_id' => 'required|integer', 'operation' => 'required|in:pack,unpack', 'remarks' => 'nullable|string|max:1000', 'materials' => 'required|array|max:100', 'materials.*' => 'array', 'outputs' => 'required|array|max:100', 'outputs.*' => 'array'];
    }
}

<?php

namespace App\Modules\FinishedGoods\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PackSpecificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('finished-goods.manage') ?? false;
    }

    public function rules(): array
    {
        return ['packed_item_id' => 'required|integer', 'description' => 'required|string|max:200', 'lines' => 'required|array|min:1|max:100', 'lines.*.component_item_id' => 'required|integer|distinct', 'lines.*.component_kind' => 'required|in:saleable_content,packaging', 'lines.*.quantity_per_pack' => 'required|numeric|gt:0'];
    }
}

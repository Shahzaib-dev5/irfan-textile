<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('production.manage') ?? false;
    }

    public function rules(): array
    {
        return ['product_variant_id' => 'required|integer|exists:product_variants,id', 'output_quantity' => 'required|numeric|gt:0', 'lines' => 'required|array|min:1|max:100', 'lines.*.component_item_id' => 'required|integer|exists:items,id', 'lines.*.component_name' => 'required|string|max:100', 'lines.*.net_quantity' => 'required|numeric|gt:0', 'lines.*.planned_wastage_fraction' => 'required|numeric|min:0|lt:1'];
    }
}

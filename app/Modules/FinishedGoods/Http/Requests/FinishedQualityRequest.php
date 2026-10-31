<?php

namespace App\Modules\FinishedGoods\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinishedQualityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('finished-goods.quality') ?? false;
    }

    public function rules(): array
    {
        return ['action' => 'required|in:quality,damage', 'stock_lot_id' => 'required|integer|exists:stock_lots,id', 'quality_status' => 'required_if:action,quality|nullable|in:pending,released,held,rejected', 'reason' => 'required|string|min:5|max:500', 'stock_position_id' => 'required_if:action,damage|nullable|integer', 'quantity' => 'required_if:action,damage|nullable|numeric|gt:0', 'document_date' => 'required_if:action,damage|nullable|date_format:Y-m-d', 'idempotency_key' => 'required_if:action,damage|nullable|uuid'];
    }
}

<?php

namespace App\Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SalesActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $a = $this->route('action');

        return $this->user()?->hasPermissionTo(in_array($a, ['reserve', 'release']) ? 'sales.stock.reserve' : ($a === 'cancel' ? 'sales.documents.manage' : 'sales.documents.confirm'));
    }

    public function rules(): array
    {
        return ['key' => ($this->route('action') === 'reserve' ? 'required' : 'nullable').'|uuid', 'lock_version' => 'required|integer|min:0', 'reason' => (in_array($this->route('action'), ['cancel', 'close', 'release']) ? 'required' : 'nullable').'|string|min:5|max:500', 'sales_order_line_id' => 'nullable|integer', 'stock_position_id' => 'nullable|integer', 'quantity' => 'nullable|decimal:0,6|gt:0', 'expires_at' => 'nullable|date'];
    }
}

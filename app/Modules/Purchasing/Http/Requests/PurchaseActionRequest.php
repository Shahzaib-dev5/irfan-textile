<?php

namespace App\Modules\Purchasing\Http\Requests;

use App\Modules\Purchasing\Services\PurchasingService;
use Illuminate\Foundation\Http\FormRequest;

class PurchaseActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $action = $this->route('action');
        $permission = match ($action) {
            'post','confirm' => PurchasingService::PERMISSIONS[app(PurchasingService::class)->kind($this->route('document'))], 'close','return_to_draft' => 'purchasing.orders.approve','reject_delivery' => 'purchasing.receipts.post',default => 'purchasing.documents.manage'
        };

        return $this->user()?->hasPermissionTo($permission) ?? false;
    }

    public function rules(): array
    {
        $action = $this->route('action');

        return ['lock_version' => in_array($action, ['close', 'reject_delivery']) ? 'sometimes|integer|min:0' : 'required|integer|min:0', 'request_key' => in_array($action, ['submit', 'post', 'confirm']) ? 'required|uuid' : 'nullable|uuid', 'reason' => in_array($action, ['cancel', 'close', 'return_to_draft', 'reject_delivery']) ? 'required|string|min:5|max:500' : 'nullable|string|max:500', 'purchase_order_line_id' => $action === 'reject_delivery' ? 'required|integer|exists:purchase_order_lines,id' : 'nullable|integer', 'rejected_quantity' => $action === 'reject_delivery' ? 'required' : 'nullable', 'supplier_challan_number' => $action === 'reject_delivery' ? 'required|string|max:100' : 'nullable|string|max:100'];
    }
}

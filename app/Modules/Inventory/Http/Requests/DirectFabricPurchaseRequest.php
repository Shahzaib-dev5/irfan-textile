<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DirectFabricPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user
            && $user->hasPermissionTo('inventory.view')
            && $user->hasPermissionTo('purchasing.documents.manage')
            && $user->hasPermissionTo('purchasing.receipts.post')
            && $user->hasPermissionTo('purchasing.bills.post');
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => 'required|uuid',
            'document_date' => 'required|date_format:Y-m-d',
            'supplier_party_role_id' => 'required|integer|exists:party_roles,id',
            'warehouse_location_id' => 'required|integer|exists:warehouse_locations,id',
            'lines' => 'required|array|min:1|max:100',
            'lines.*' => 'required|array',
            'lines.*.item_id' => 'required_without:lines.*.new_item.sku|nullable|integer|exists:items,id',
            'lines.*.new_item' => 'nullable|array',
            'lines.*.new_item.sku' => 'required_without:lines.*.item_id|nullable|string|max:60',
            'lines.*.new_item.name' => 'required_with:lines.*.new_item.sku|nullable|string|max:200',
            'lines.*.new_item.category_id' => 'required_with:lines.*.new_item.sku|nullable|integer|exists:item_categories,id',
            'lines.*.quantity' => 'required|decimal:0,6|gt:0',
            'lines.*.unit_cost' => 'required|decimal:0,8|gt:0',
            'lines.*.lot_number' => 'required|string|max:50|distinct',
            'lines.*.roll_number' => 'nullable|string|max:60|distinct',
            'supplier_invoice_number' => 'required|string|max:100',
            'supplier_invoice_date' => 'required|date_format:Y-m-d',
            'due_on' => 'required|date_format:Y-m-d|after_or_equal:supplier_invoice_date',
            'payment_status' => 'required|in:unpaid,partial,paid',
            'payment_amount' => 'required_unless:payment_status,unpaid|nullable|decimal:0,4|gt:0',
            'bank_account_id' => 'required_unless:payment_status,unpaid|nullable|integer|exists:bank_accounts,id',
            'payment_method' => 'required_unless:payment_status,unpaid|nullable|in:cash,bank_transfer,cheque,other',
            'payment_reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:5000',
        ];
    }
}

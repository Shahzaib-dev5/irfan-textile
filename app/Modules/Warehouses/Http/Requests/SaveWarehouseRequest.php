<?php

namespace App\Modules\Warehouses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('warehouses.manage') ?? false;
    }

    public function rules(): array
    {
        return ['code' => 'required|string|max:30', 'name' => 'required|string|max:120', 'is_active' => 'required|boolean', 'address' => 'nullable|string|max:500', 'warehouse_id' => 'nullable|integer', 'location_type' => 'nullable|in:internal,contractor,transit,quarantine', 'contractor_vendor_party_role_id' => 'nullable|integer'];
    }
}

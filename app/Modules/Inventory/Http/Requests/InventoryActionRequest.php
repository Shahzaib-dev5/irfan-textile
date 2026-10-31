<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = match ($this->route('action')) {
            'post' => 'post','reverse' => 'reverse','reserve','release' => 'reserve',default => 'manage'
        };

        return $this->user()?->hasPermissionTo('inventory.'.$permission) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['action' => $this->route('action')]);
    }

    public function rules(): array
    {
        return ['lock_version' => 'required_unless:action,reverse|integer|min:0', 'posting_key' => 'required_if:action,post,reverse|uuid', 'reason' => 'required_if:action,cancel,reverse,release|nullable|string|min:5|max:1000', 'document_date' => 'required_if:action,reverse|nullable|date_format:Y-m-d', 'expires_at' => 'nullable|date|after:now'];
    }
}

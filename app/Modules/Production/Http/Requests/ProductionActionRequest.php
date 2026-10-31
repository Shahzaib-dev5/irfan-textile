<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductionActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo($this->route('action') === 'post' ? 'production.confirm' : 'production.manage') ?? false;
    }

    public function rules(): array
    {
        return ['lock_version' => 'required|integer|min:0', 'request_key' => 'required|uuid', 'reason' => $this->route('action') === 'cancel' ? 'required|string|min:5|max:500' : 'nullable|string'];
    }
}

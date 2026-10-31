<?php

namespace App\Modules\Identity\Http\Requests;

use App\Models\AccessRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', AccessRole::class);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500'], 'is_active' => ['required', 'boolean'],
            'permission_ids' => ['sometimes', 'array'], 'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')]];
    }
}

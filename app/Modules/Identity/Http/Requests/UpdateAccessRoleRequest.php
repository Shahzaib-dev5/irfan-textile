<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccessRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('role'));
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500'], 'is_active' => ['required', 'boolean'],
            'permission_ids' => ['sometimes', 'array'], 'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')]];
    }
}

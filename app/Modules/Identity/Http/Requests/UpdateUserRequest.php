<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return ['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:254', 'lowercase', Rule::unique('users', 'email')->ignore($user)],
            'password' => ['nullable', Password::min(14)->mixedCase()->numbers()->symbols()], 'is_active' => ['required', 'boolean'],
            'role_ids' => ['required', 'array', 'min:1'], 'role_ids.*' => ['integer', Rule::exists('access_roles', 'id')->where('is_active', true)]];
    }
}

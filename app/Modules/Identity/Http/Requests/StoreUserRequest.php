<?php

namespace App\Modules\Identity\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:254', 'lowercase', 'unique:users,email'],
            'password' => ['required', Password::min(14)->mixedCase()->numbers()->symbols()], 'is_active' => ['required', 'boolean'],
            'role_ids' => ['required', 'array', 'min:1'], 'role_ids.*' => ['integer', Rule::exists('access_roles', 'id')->where('is_active', true)]];
    }
}

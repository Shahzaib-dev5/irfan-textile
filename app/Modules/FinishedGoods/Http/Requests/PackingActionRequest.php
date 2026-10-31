<?php

namespace App\Modules\FinishedGoods\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PackingActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo($this->route('action') === 'post' ? 'finished-goods.confirm' : 'finished-goods.manage') ?? false;
    }

    public function rules(): array
    {
        return ['lock_version' => 'required|integer|min:0', 'request_key' => 'required|uuid', 'reason' => $this->route('action') === 'cancel' ? 'required|string|min:5|max:500' : 'nullable|string'];
    }
}

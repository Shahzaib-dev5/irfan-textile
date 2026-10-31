<?php

namespace App\Modules\Processing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessingActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('processing.documents.view') ?? false;
    }

    public function rules(): array
    {
        return ['request_key' => 'required|uuid', 'lock_version' => 'required|integer|min:0', 'reason' => 'nullable|string|max:500'];
    }
}

<?php

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Services\SalesService;
use Illuminate\Foundation\Http\FormRequest;

class SalesDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('sales.documents.manage') && ($this->input('intent') !== 'confirm' || $this->user()->hasPermissionTo('sales.documents.confirm'));
    }

    public function rules(): array
    {
        return SalesService::rules() + ['intent' => 'required|in:draft,confirm'];
    }
}

<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\CatalogRegistry;
use Illuminate\Foundation\Http\FormRequest;

class SaveConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('item'));
    }

    public function rules(): array
    {
        return CatalogRegistry::conversionRules();
    }
}

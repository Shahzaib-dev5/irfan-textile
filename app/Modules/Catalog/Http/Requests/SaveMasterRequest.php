<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\CatalogRegistry;
use Illuminate\Foundation\Http\FormRequest;

class SaveMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = CatalogRegistry::model($this->route('master'));

        return $this->route('record')
            ? $this->user()->can('update', $model::query()->findOrFail($this->route('record')))
            : $this->user()->can('create', $model);
    }

    public function rules(): array
    {
        return CatalogRegistry::rules($this->route('master'), $this->route('record') ? (int) $this->route('record') : null);
    }
}

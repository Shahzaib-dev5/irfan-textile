<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Services\PhysicalSuitStock;
use Illuminate\Foundation\Http\FormRequest;

class PhysicalSuitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermissionTo('inventory.manage');
    }

    public function rules(): array
    {
        return PhysicalSuitStock::rules();
    }
}

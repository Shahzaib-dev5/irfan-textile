<?php

namespace App\Modules\Processing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FabricFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('processing.documents.manage') ?? false;
    }

    public static function fields(): array
    {
        return [
            'kind' => 'required|in:out,in', 'intent' => 'required|in:save,confirm',
            'request_key' => 'required|uuid', 'document_date' => 'required|date_format:Y-m-d',
            'vendor_party_role_id' => 'required_if:kind,out|nullable|integer',
            'vendor_service_id' => 'required_if:kind,out|nullable|integer',
            'source_stock_position_id' => 'required_if:kind,out|nullable|integer',
            'quantity' => 'required_if:kind,out|nullable|numeric|gt:0',
            'contractor_location_id' => 'nullable|integer',
            'agreed_unit_rate_pkr' => 'nullable|numeric|min:0',
            'override_reason' => 'nullable|string|max:500',
            'normal_loss_percent' => 'nullable|numeric|min:0|lt:100',
            'fabric_type' => 'required_if:kind,out|nullable|string|max:100',
            'color' => 'nullable|string|max:100', 'design_reference' => 'nullable|string|max:100',
            'component_type' => 'nullable|string|max:100', 'instructions' => 'nullable|string|max:500',
            'manufacturing_batch_document_id' => 'nullable|integer|exists:manufacturing_batches,document_id',
            'processing_dispatch_line_id' => 'required_if:kind,in|nullable|integer',
            'destination' => 'required_if:kind,in|nullable|in:warehouse,vendor',
            'disposition' => 'required_if:kind,in|nullable|in:accepted,rejected',
            'source_quantity' => 'required_if:kind,in|nullable|numeric|gt:0',
            'output_quantity' => 'required_if:kind,in|nullable|numeric|gt:0',
            'normal_loss_quantity' => 'nullable|numeric|min:0', 'abnormal_loss_quantity' => 'nullable|numeric|min:0',
            'accepted_service_quantity' => 'nullable|numeric|min:0', 'remarks' => 'nullable|string|max:1000',
            'receiving_location_id' => 'nullable|integer', 'quality_status' => 'nullable|in:pending,released,held,rejected',
            'laat_reference' => 'required_if:kind,in|nullable|string|max:80',
            'component_count' => 'nullable|integer|min:1|max:1000000000',
            'roll_number' => 'nullable|string|max:80',
            'next_vendor_party_role_id' => 'required_if:destination,vendor|nullable|integer',
            'next_vendor_service_id' => 'required_if:destination,vendor|nullable|integer',
            'next_contractor_location_id' => 'nullable|integer',
            'next_agreed_unit_rate_pkr' => 'nullable|numeric|min:0',
            'next_override_reason' => 'nullable|string|max:500',
            'next_normal_loss_percent' => 'nullable|numeric|min:0|lt:100',
        ];
    }

    public function rules(): array
    {
        return self::fields();
    }
}

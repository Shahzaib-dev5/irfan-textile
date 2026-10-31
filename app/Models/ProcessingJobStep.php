<?php

namespace App\Models;

class ProcessingJobStep extends InventoryDocumentPart
{
    protected function casts(): array
    {
        return ['planned_input_quantity' => 'decimal:6', 'planned_output_quantity' => 'decimal:6', 'agreed_unit_rate_pkr' => 'decimal:8', 'rate_effective_date' => 'date', 'permitted_normal_loss_fraction' => 'decimal:10'];
    }

    public function job()
    {
        return $this->belongsTo(ProcessingJob::class, 'document_id', 'document_id');
    }

    public function vendorService()
    {
        return $this->belongsTo(VendorService::class);
    }

    public function rate()
    {
        return $this->belongsTo(VendorServiceRate::class, 'vendor_service_rate_id');
    }

    public function service()
    {
        return $this->belongsTo(ProcessingService::class, 'processing_service_id');
    }

    public function billingUnit()
    {
        return $this->belongsTo(Unit::class, 'billing_unit_id');
    }

    public function inputItem()
    {
        return $this->belongsTo(Item::class, 'input_item_id');
    }

    public function outputItem()
    {
        return $this->belongsTo(Item::class, 'output_item_id');
    }
}

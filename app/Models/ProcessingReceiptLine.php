<?php

namespace App\Models;

class ProcessingReceiptLine extends InventoryDocumentPart
{
    public function getBillingUnitIdAttribute($value)
    {
        return $value ?? ($this->relationLoaded('step') ? $this->step->billing_unit_id : $this->step()->value('billing_unit_id'));
    }

    public function getUnitRatePkrAttribute($value)
    {
        return $value ?? ($this->relationLoaded('step') ? $this->step->agreed_unit_rate_pkr : $this->step()->value('agreed_unit_rate_pkr'));
    }

    public function billingUnit()
    {
        return $this->belongsTo(Unit::class, 'billing_unit_id');
    }

    protected function casts(): array
    {
        return ['source_quantity' => 'decimal:6', 'output_quantity' => 'decimal:6', 'output_source_equivalent_quantity' => 'decimal:6', 'normal_loss_quantity' => 'decimal:6', 'abnormal_loss_quantity' => 'decimal:6', 'accepted_service_quantity' => 'decimal:6', 'service_accrual_pkr' => 'decimal:4', 'abnormal_loss_cost_pkr' => 'decimal:4'];
    }

    public function receipt()
    {
        return $this->belongsTo(ProcessingReceipt::class, 'document_id', 'document_id');
    }

    public function dispatchLine()
    {
        return $this->belongsTo(ProcessingDispatchLine::class, 'processing_dispatch_line_id');
    }

    public function step()
    {
        return $this->belongsTo(ProcessingJobStep::class, 'processing_job_step_id');
    }

    public function output()
    {
        return $this->belongsTo(StockPosition::class, 'output_stock_position_id');
    }
}

<?php

namespace App\Models;

class ProcessingDispatchLine extends InventoryDocumentPart
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:6'];
    }

    public function dispatch()
    {
        return $this->belongsTo(ProcessingDispatch::class, 'document_id', 'document_id');
    }

    public function step()
    {
        return $this->belongsTo(ProcessingJobStep::class, 'processing_job_step_id');
    }

    public function source()
    {
        return $this->belongsTo(StockPosition::class, 'source_stock_position_id');
    }

    public function contractor()
    {
        return $this->belongsTo(StockPosition::class, 'contractor_stock_position_id');
    }

    public function receipts()
    {
        return $this->hasMany(ProcessingReceiptLine::class);
    }
}

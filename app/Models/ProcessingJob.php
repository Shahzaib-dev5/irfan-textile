<?php

namespace App\Models;

class ProcessingJob extends InventoryDocumentPart
{
    protected $primaryKey = 'document_id';

    public $incrementing = false;

    public function batch()
    {
        return $this->belongsTo(ManufacturingBatch::class, 'manufacturing_batch_document_id', 'document_id');
    }

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    protected function casts(): array
    {
        return ['planned_input_quantity' => 'decimal:6', 'required_by' => 'date'];
    }

    public function route()
    {
        return $this->belongsTo(ProcessingRouteVersion::class, 'processing_route_version_id');
    }

    public function lines()
    {
        return $this->hasMany(ProcessingJobStep::class, 'document_id', 'document_id')->orderBy('line_no');
    }
}

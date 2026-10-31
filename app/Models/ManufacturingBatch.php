<?php

namespace App\Models;

class ManufacturingBatch extends InventoryDocumentPart
{
    protected $primaryKey = 'document_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['planned_output_quantity' => 'decimal:6', 'planned_start_on' => 'date', 'planned_finish_on' => 'date'];
    }

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function bom()
    {
        return $this->belongsTo(BillOfMaterial::class, 'bill_of_material_id');
    }

    public function lines()
    {
        return $this->hasMany(ManufacturingBatchMaterial::class, 'document_id', 'document_id');
    }

    public function reports()
    {
        return $this->hasMany(ProductionReport::class, 'manufacturing_batch_document_id', 'document_id');
    }

    public function processingJobs()
    {
        return $this->hasMany(ProcessingJob::class, 'manufacturing_batch_document_id', 'document_id');
    }
}

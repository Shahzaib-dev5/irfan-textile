<?php

namespace App\Models;

class ProductionReport extends InventoryDocumentPart
{
    protected $primaryKey = 'document_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['is_final' => 'boolean'];
    }

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function batch()
    {
        return $this->belongsTo(ManufacturingBatch::class, 'manufacturing_batch_document_id', 'document_id');
    }

    public function materials()
    {
        return $this->hasMany(ProductionMaterialLine::class, 'document_id', 'document_id');
    }

    public function outputs()
    {
        return $this->hasMany(ProductionOutputLine::class, 'document_id', 'document_id');
    }

    public function wastage()
    {
        return $this->hasMany(ProductionWastageLine::class, 'document_id', 'document_id');
    }

    public function costs()
    {
        return $this->hasMany(ProductionCostAllocation::class, 'document_id', 'document_id');
    }
}

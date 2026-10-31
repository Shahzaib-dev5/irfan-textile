<?php

namespace App\Models;

class ProcessingReceipt extends InventoryDocumentPart
{
    protected $primaryKey = 'document_id';

    public $incrementing = false;

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    protected function casts(): array
    {
        return [];
    }

    public function job()
    {
        return $this->belongsTo(ProcessingJob::class, 'processing_job_document_id', 'document_id');
    }

    public function vendor()
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_party_role_id', 'party_role_id');
    }

    public function lines()
    {
        return $this->hasMany(ProcessingReceiptLine::class, 'document_id', 'document_id')->orderBy('line_no');
    }
}

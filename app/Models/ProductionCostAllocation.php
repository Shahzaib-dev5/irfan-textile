<?php

namespace App\Models;

class ProductionCostAllocation extends InventoryDocumentPart
{
    public function report()
    {
        return $this->belongsTo(ProductionReport::class, 'document_id', 'document_id');
    }

    protected function casts(): array
    {
        return ['amount_pkr' => 'decimal:4'];
    }

    public function source()
    {
        return $this->belongsTo(JournalLine::class, 'source_journal_line_id');
    }

    public function output()
    {
        return $this->belongsTo(ProductionOutputLine::class, 'production_output_line_id');
    }
}

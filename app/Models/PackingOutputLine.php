<?php

namespace App\Models;

class PackingOutputLine extends InventoryDocumentPart
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'cost_allocation_fraction' => 'decimal:10'];
    }

    public function order()
    {
        return $this->belongsTo(PackingOrder::class, 'document_id', 'document_id');
    }

    public function position()
    {
        return $this->belongsTo(StockPosition::class, 'stock_position_id');
    }

    public function packages()
    {
        return $this->hasMany(Package::class, 'packing_output_line_id');
    }
}

<?php

namespace App\Models;

class ProductionOutputLine extends InventoryDocumentPart
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'cost_allocation_fraction' => 'decimal:10'];
    }

    public function position()
    {
        return $this->belongsTo(StockPosition::class, 'stock_position_id');
    }
}

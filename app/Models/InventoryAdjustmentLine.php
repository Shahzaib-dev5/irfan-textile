<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustmentLine extends InventoryDocumentPart
{
    protected function casts(): array
    {
        return ['quantity_delta' => 'decimal:6', 'counted_quantity' => 'decimal:6', 'expected_quantity' => 'decimal:6', 'positive_unit_cost_pkr' => 'decimal:8'];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(StockPosition::class, 'stock_position_id');
    }
}

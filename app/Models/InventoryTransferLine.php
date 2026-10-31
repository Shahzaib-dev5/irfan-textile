<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransferLine extends InventoryDocumentPart
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:6'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(StockPosition::class, 'source_stock_position_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(StockPosition::class, 'destination_stock_position_id');
    }
}

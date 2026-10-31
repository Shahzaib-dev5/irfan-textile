<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends InventoryRecord
{
    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'fulfilled_quantity' => 'decimal:6', 'released_quantity' => 'decimal:6', 'expires_at' => 'datetime'];
    }
}

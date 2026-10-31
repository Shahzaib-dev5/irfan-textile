<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockMovement extends InventoryHistory
{
    protected function casts(): array
    {
        return ['quantity_delta' => 'decimal:6'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(StockPosition::class, 'stock_position_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InventoryLayerAllocation::class);
    }
}

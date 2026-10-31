<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCostLayer extends InventoryRecord
{
    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (StockCostLayer $layer) {
            if (array_diff(array_keys($layer->getDirty()), ['remaining_quantity', 'updated_at'])) {
                throw new \LogicException('Only the FIFO remaining-quantity projection may change.');
            }
        });
    }

    protected function casts(): array
    {
        return ['remaining_quantity' => 'decimal:6', 'fifo_date' => 'date'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InventoryLayerAllocation::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(InventoryValueEntry::class);
    }
}

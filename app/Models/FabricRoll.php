<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FabricRoll extends InventoryRecord
{
    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (FabricRoll $roll) {
            if (StockPosition::where('fabric_roll_id', $roll->id)->whereHas('movements')->exists() && $roll->isDirty(['stock_lot_id', 'roll_number', 'width_mm'])) {
                throw new \LogicException('Posted roll identity and measured width are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['width_mm' => 'decimal:3', 'is_active' => 'boolean'];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }
}

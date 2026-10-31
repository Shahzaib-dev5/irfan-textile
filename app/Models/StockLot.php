<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockLot extends InventoryRecord
{
    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (StockLot $lot) {
            if ($lot->positions()->whereHas('movements')->exists() && $lot->isDirty(['item_id', 'lot_number', 'origin_document_id'])) {
                throw new \LogicException('Posted lot identity is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['manufactured_on' => 'date', 'expires_on' => 'date'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'origin_document_id');
    }

    public function rolls(): HasMany
    {
        return $this->hasMany(FabricRoll::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(StockPosition::class);
    }
}

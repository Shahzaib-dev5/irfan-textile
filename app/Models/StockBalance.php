<?php

namespace App\Models;

class StockBalance extends InventoryRecord
{
    protected $primaryKey = 'stock_position_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['on_hand_quantity' => 'decimal:6', 'reserved_quantity' => 'decimal:6'];
    }
}

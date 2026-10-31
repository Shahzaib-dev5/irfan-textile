<?php

namespace App\Models;

class InventoryValueEntry extends InventoryHistory
{
    protected function casts(): array
    {
        return ['amount_pkr' => 'decimal:4'];
    }
}

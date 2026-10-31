<?php

namespace App\Models;

class InventoryLayerAllocation extends InventoryHistory
{
    protected function casts(): array
    {
        return ['quantity_delta' => 'decimal:6'];
    }
}

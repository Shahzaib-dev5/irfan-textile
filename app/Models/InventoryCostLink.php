<?php

namespace App\Models;

class InventoryCostLink extends InventoryHistory
{
    protected function casts(): array
    {
        return ['allocation_fraction' => 'decimal:10', 'allocated_cost_pkr' => 'decimal:4'];
    }
}

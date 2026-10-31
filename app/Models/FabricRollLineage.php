<?php

namespace App\Models;

class FabricRollLineage extends InventoryHistory
{
    protected $table = 'fabric_roll_lineage';

    protected function casts(): array
    {
        return ['parent_quantity_used' => 'decimal:6'];
    }
}

<?php

namespace App\Models;

class ProductionWastageLine extends InventoryDocumentPart
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'expense_amount_pkr' => 'decimal:4'];
    }

    public function material()
    {
        return $this->belongsTo(ProductionMaterialLine::class, 'production_material_line_id');
    }
}

<?php

namespace App\Models;

class ProductionMaterialLine extends InventoryDocumentPart
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:6'];
    }

    public function plan()
    {
        return $this->belongsTo(ManufacturingBatchMaterial::class, 'manufacturing_batch_material_id');
    }

    public function position()
    {
        return $this->belongsTo(StockPosition::class, 'stock_position_id');
    }

    public function wastage()
    {
        return $this->hasMany(ProductionWastageLine::class, 'production_material_line_id');
    }
}

<?php

namespace App\Models;

class ManufacturingBatchMaterial extends InventoryDocumentPart
{
    protected function casts(): array
    {
        return ['planned_net_quantity' => 'decimal:6', 'planned_gross_quantity' => 'decimal:6'];
    }

    public function batch()
    {
        return $this->belongsTo(ManufacturingBatch::class, 'document_id', 'document_id');
    }

    public function bomLine()
    {
        return $this->belongsTo(BillOfMaterialLine::class, 'bill_of_material_line_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'component_item_id');
    }
}

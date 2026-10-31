<?php

namespace App\Models;

class BillOfMaterialLine extends InventoryRecord
{
    protected function casts(): array
    {
        return ['net_quantity' => 'decimal:6', 'planned_wastage_fraction' => 'decimal:10'];
    }

    public function bom()
    {
        return $this->belongsTo(BillOfMaterial::class, 'bill_of_material_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'component_item_id');
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function ($model) {
            if (BillOfMaterial::whereKey($model->bill_of_material_id)->value('state') !== 'draft') {
                throw new \LogicException('Published BOM definitions are immutable. Create a new version.');
            }
        });
        static::creating(function ($model) {
            if (BillOfMaterial::whereKey($model->bill_of_material_id)->value('state') !== 'draft') {
                throw new \LogicException('Published BOM lines are immutable.');
            }
        });
    }
}

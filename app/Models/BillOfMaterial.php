<?php

namespace App\Models;

class BillOfMaterial extends InventoryRecord
{
    protected $table = 'bills_of_materials';

    protected function casts(): array
    {
        return ['output_quantity' => 'decimal:6', 'is_active' => 'boolean', 'version_number' => 'integer'];
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function lines()
    {
        return $this->hasMany(BillOfMaterialLine::class, 'bill_of_material_id');
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function ($model) {
            if ($model->getOriginal('state') !== 'draft') {
                throw new \LogicException('Published BOM definitions are immutable. Create a new version.');
            }
        });
    }
}

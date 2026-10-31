<?php

namespace App\Models;

class ProductPackSpecLine extends InventoryRecord
{
    protected function casts(): array
    {
        return ['quantity_per_pack' => 'decimal:6'];
    }

    public function spec()
    {
        return $this->belongsTo(ProductPackSpec::class, 'product_pack_spec_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'component_item_id');
    }

    protected static function booted(): void
    {
        parent::booted();
        $check = function ($m) {
            if ($m->spec->state !== 'draft') {
                throw new \LogicException('Published packing specification lines are immutable.');
            }
        };
        static::creating($check);
        static::updating($check);
    }
}

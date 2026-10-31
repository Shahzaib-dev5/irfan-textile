<?php

namespace App\Models;

class ProductPackSpec extends InventoryRecord
{
    protected function casts(): array
    {
        return ['version_number' => 'integer', 'is_active' => 'boolean'];
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'packed_item_id');
    }

    public function lines()
    {
        return $this->hasMany(ProductPackSpecLine::class);
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function ($m) {
            if ($m->getOriginal('state') !== 'draft') {
                throw new \LogicException('Published packing specifications are immutable. Publish a new version.');
            }
        });
    }
}

<?php

namespace App\Models;

class PackageContent extends InventoryRecord
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:6'];
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function material()
    {
        return $this->belongsTo(PackingMaterialLine::class, 'packing_material_line_id');
    }

    protected static function booted(): void
    {
        parent::booted();
        $check = function ($m) {
            if ($m->package->output->order->document->status !== 'draft') {
                throw new \LogicException('Posted package contents are immutable.');
            }
        };
        static::creating($check);
        static::updating($check);
    }
}

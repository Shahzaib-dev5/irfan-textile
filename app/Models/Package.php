<?php

namespace App\Models;

class Package extends InventoryRecord
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'gross_weight_kg' => 'decimal:3', 'sealed_at' => 'datetime'];
    }

    public function output()
    {
        return $this->belongsTo(PackingOutputLine::class, 'packing_output_line_id');
    }

    public function contents()
    {
        return $this->hasMany(PackageContent::class);
    }

    protected static function booted(): void
    {
        parent::booted();
        $check = function ($m) {
            if ($m->output->order->document->status !== 'draft') {
                throw new \LogicException('Posted package metadata is immutable.');
            }
        };
        static::creating($check);
        static::updating($check);
    }
}

<?php

namespace App\Models;

class ProcessingRouteVersion extends InventoryRecord
{
    protected static function booted(): void
    {
        parent::booted();
        static::updating(function ($m) {
            if ($m->getOriginal('state') !== 'draft') {
                throw new \LogicException('Published routes are immutable; create another version.');
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function steps()
    {
        return $this->hasMany(ProcessingRouteStep::class)->orderBy('step_number');
    }
}

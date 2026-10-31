<?php

namespace App\Models;

class ProcessingRouteStep extends InventoryRecord
{
    protected static function booted(): void
    {
        parent::booted();
        $guard = function ($m) {
            if (ProcessingRouteVersion::findOrFail($m->processing_route_version_id)->state !== 'draft') {
                throw new \LogicException('Published route steps are immutable.');
            }
        };
        static::creating($guard);
        static::updating($guard);
    }

    protected function casts(): array
    {
        return ['planned_yield_ratio' => 'decimal:10', 'permitted_normal_loss_fraction' => 'decimal:10'];
    }

    public function route()
    {
        return $this->belongsTo(ProcessingRouteVersion::class, 'processing_route_version_id');
    }

    public function service()
    {
        return $this->belongsTo(ProcessingService::class, 'processing_service_id');
    }

    public function inputItem()
    {
        return $this->belongsTo(Item::class, 'input_item_id');
    }

    public function outputItem()
    {
        return $this->belongsTo(Item::class, 'output_item_id');
    }
}

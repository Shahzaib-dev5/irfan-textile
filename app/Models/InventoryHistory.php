<?php

namespace App\Models;

abstract class InventoryHistory extends InventoryRecord
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function () {
            throw new \LogicException('Posted inventory history is immutable.');
        });
    }
}

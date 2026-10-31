<?php

namespace App\Models;

abstract class InventoryDocumentPart extends InventoryRecord
{
    protected static function booted(): void
    {
        parent::booted();
        $guard = function ($model) {
            if (Document::query()->whereKey($model->document_id)->value('status') !== 'draft') {
                throw new \LogicException('Posted document details cannot be edited.');
            }
        };
        static::creating($guard);
        static::updating($guard);
    }
}

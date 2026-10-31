<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryAdjustment extends InventoryDocumentPart
{
    protected $primaryKey = 'document_id';

    public $incrementing = false;

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryAdjustmentLine::class, 'document_id', 'document_id')->orderBy('line_no');
    }
}

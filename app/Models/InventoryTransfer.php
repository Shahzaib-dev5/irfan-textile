<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryTransfer extends InventoryDocumentPart
{
    protected $primaryKey = 'document_id';

    public $incrementing = false;

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryTransferLine::class, 'document_id', 'document_id')->orderBy('line_no');
    }
}

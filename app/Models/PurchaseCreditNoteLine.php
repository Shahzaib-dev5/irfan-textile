<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseCreditNoteLine extends InventoryDocumentPart
{
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}

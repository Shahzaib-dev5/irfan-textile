<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReturn extends InventoryDocumentPart
{
    protected $primaryKey = 'document_id';

    public $incrementing = false;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(PartyRole::class, 'supplier_party_role_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class, 'document_id', 'document_id')->orderBy('line_no');
    }
}

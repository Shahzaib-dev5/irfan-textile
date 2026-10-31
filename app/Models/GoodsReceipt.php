<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends InventoryDocumentPart
{
    protected $primaryKey = 'document_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'supplier_challan_date' => 'date'];
    }

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
        return $this->hasMany(GoodsReceiptLine::class, 'document_id', 'document_id')->orderBy('line_no');
    }
}

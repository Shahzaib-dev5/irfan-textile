<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderLine extends InventoryDocumentPart
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'base_quantity' => 'decimal:6', 'conversion_factor' => 'decimal:8', 'unit_price' => 'decimal:8', 'discount_amount' => 'decimal:4', 'expected_on' => 'date'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'document_id', 'document_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }
}

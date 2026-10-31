<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoiceLine extends InventoryDocumentPart
{
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'document_id', 'document_id');
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'base_quantity' => 'decimal:6', 'conversion_factor' => 'decimal:8', 'unit_price' => 'decimal:8', 'discount_amount' => 'decimal:4', 'net_amount' => 'decimal:4', 'gross_amount' => 'decimal:4'];
    }

    public function matches(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceReceiptMatch::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}

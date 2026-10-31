<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceReceiptMatch extends InventoryRecord
{
    protected function casts(): array
    {
        return ['matched_base_quantity' => 'decimal:6', 'net_amount' => 'decimal:4', 'provisional_value_relieved' => 'decimal:4'];
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class, 'goods_receipt_line_id');
    }

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function ($match) {
            if (Document::whereKey(PurchaseInvoiceLine::findOrFail($match->purchase_invoice_line_id)->document_id)->value('status') !== 'draft') {
                throw new \LogicException('Posted invoice matches are immutable.');
            }
        });
        static::updating(function ($match) {
            if (Document::whereKey(PurchaseInvoiceLine::findOrFail($match->purchase_invoice_line_id)->document_id)->value('status') !== 'draft') {
                throw new \LogicException('Posted invoice matches are immutable.');
            }
        });
    }
}

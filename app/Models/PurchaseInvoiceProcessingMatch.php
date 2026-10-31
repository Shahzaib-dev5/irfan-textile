<?php

namespace App\Models;

class PurchaseInvoiceProcessingMatch extends InventoryRecord
{
    protected static function booted(): void
    {
        parent::booted();
        $guard = function ($m) {
            $line = PurchaseInvoiceLine::findOrFail($m->purchase_invoice_line_id);
            if (Document::findOrFail($line->document_id)->status !== 'draft') {
                throw new \LogicException('Posted matches are immutable.');
            }
        };
        static::creating($guard);
        static::updating($guard);
    }

    public function invoiceLine()
    {
        return $this->belongsTo(PurchaseInvoiceLine::class, 'purchase_invoice_line_id');
    }

    protected function casts(): array
    {
        return ['billed_service_quantity' => 'decimal:6', 'net_amount' => 'decimal:4', 'accrued_value_relieved' => 'decimal:4'];
    }

    public function receiptLine()
    {
        return $this->belongsTo(ProcessingReceiptLine::class, 'processing_receipt_line_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesCreditNoteLine extends InventoryDocumentPart
{
    protected $table = 'sales_credit_note_lines';

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(SalesCreditNote::class, 'document_id', 'document_id');
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceDispatchMatch::class, 'sales_invoice_dispatch_match_id', 'id');
    }

    public function returnLine(): BelongsTo
    {
        return $this->belongsTo(SalesReturnLine::class, 'sales_return_line_id', 'id');
    }

    protected function casts(): array
    {
        return ['credited_base_quantity' => 'decimal:6', 'net_amount' => 'decimal:4'];
    }
}

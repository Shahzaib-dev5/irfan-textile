<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInvoiceLine extends InventoryDocumentPart
{
    protected $table = 'sales_invoice_lines';

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'document_id', 'document_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(SalesInvoiceDispatchMatch::class, 'sales_invoice_line_id', 'id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id', 'id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'base_quantity' => 'decimal:6', 'net_amount' => 'decimal:4', 'unit_price' => 'decimal:8', 'discount_amount' => 'decimal:4'];
    }
}

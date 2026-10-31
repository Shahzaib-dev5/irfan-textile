<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderLine extends InventoryDocumentPart
{
    protected $table = 'sales_order_lines';

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'document_id', 'document_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'id');
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'base_quantity' => 'decimal:6', 'conversion_factor' => 'decimal:8', 'unit_price' => 'decimal:8', 'discount_amount' => 'decimal:4'];
    }
}

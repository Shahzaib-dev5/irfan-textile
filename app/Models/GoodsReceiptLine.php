<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptLine extends InventoryDocumentPart
{
    protected function casts(): array
    {
        return ['received_quantity' => 'decimal:6', 'accepted_quantity' => 'decimal:6', 'rejected_quantity' => 'decimal:6', 'accepted_base_quantity' => 'decimal:6', 'conversion_factor' => 'decimal:8', 'provisional_unit_cost' => 'decimal:8', 'provisional_value' => 'decimal:4'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'document_id', 'document_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(StockPosition::class, 'stock_position_id');
    }
}

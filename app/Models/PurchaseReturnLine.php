<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnLine extends InventoryDocumentPart
{
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    protected function casts(): array
    {
        return ['base_quantity' => 'decimal:6'];
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class, 'goods_receipt_line_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(StockPosition::class, 'stock_position_id');
    }
}

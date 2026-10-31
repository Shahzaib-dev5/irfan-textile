<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesDispatchLine extends InventoryDocumentPart
{
    protected $table = 'sales_dispatch_lines';

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(SalesDispatch::class, 'document_id', 'document_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id', 'id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(StockPosition::class, 'stock_position_id', 'id');
    }

    protected function casts(): array
    {
        return ['base_quantity' => 'decimal:6', 'net_revenue_amount' => 'decimal:4'];
    }
}

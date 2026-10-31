<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnLine extends InventoryDocumentPart
{
    protected $table = 'sales_return_lines';

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function return(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'document_id', 'document_id');
    }

    public function dispatchLine(): BelongsTo
    {
        return $this->belongsTo(SalesDispatchLine::class, 'sales_dispatch_line_id', 'id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(StockPosition::class, 'stock_position_id', 'id');
    }

    protected function casts(): array
    {
        return ['base_quantity' => 'decimal:6'];
    }
}

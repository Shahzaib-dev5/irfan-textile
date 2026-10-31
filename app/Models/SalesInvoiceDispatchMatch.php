<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInvoiceDispatchMatch extends InventoryRecord
{
    protected function casts(): array
    {
        return ['matched_base_quantity' => 'decimal:6', 'net_amount' => 'decimal:4'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceLine::class, 'sales_invoice_line_id');
    }

    public function dispatchLine(): BelongsTo
    {
        return $this->belongsTo(SalesDispatchLine::class, 'sales_dispatch_line_id');
    }

    protected static function booted(): void
    {
        parent::booted();
        $guard = function ($m) {
            if (SalesInvoiceLine::findOrFail($m->sales_invoice_line_id)->document->status !== 'draft') {
                throw new \LogicException('Posted invoice matching is immutable.');
            }
        };
        static::creating($guard);
        static::updating($guard);
    }
}

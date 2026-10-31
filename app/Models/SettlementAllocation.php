<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementAllocation extends InventoryHistory
{
    protected $table = 'settlement_allocations';

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function debitItem(): BelongsTo
    {
        return $this->belongsTo(PartyOpenItem::class, 'debit_open_item_id');
    }

    public function creditItem(): BelongsTo
    {
        return $this->belongsTo(PartyOpenItem::class, 'credit_open_item_id');
    }
}

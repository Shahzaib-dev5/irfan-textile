<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends InventoryHistory
{
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'journal_entry_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    protected function casts(): array
    {
        return ['debit' => 'decimal:4', 'credit' => 'decimal:4'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}

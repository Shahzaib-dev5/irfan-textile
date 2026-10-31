<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends InventoryRecord
{
    protected $table = 'bank_accounts';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }
}

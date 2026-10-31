<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends InventoryHistory
{
    public const UPDATED_AT = 'updated_at';

    protected $primaryKey = 'document_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['total_debit' => 'decimal:4', 'total_credit' => 'decimal:4'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id', 'document_id');
    }
}

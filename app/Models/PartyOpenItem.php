<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartyOpenItem extends InventoryHistory
{
    protected function casts(): array
    {
        return ['original_amount' => 'decimal:4', 'due_on' => 'date'];
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }
}

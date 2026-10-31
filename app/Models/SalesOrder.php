<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends InventoryDocumentPart
{
    protected $table = 'sales_orders';

    protected $primaryKey = 'document_id';

    public $incrementing = false;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class, 'document_id', 'document_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(PartyRole::class, 'customer_party_role_id', 'id');
    }

    protected function casts(): array
    {
        return ['required_on' => 'date'];
    }
}

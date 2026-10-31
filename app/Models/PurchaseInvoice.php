<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoice extends InventoryDocumentPart
{
    protected $primaryKey = 'document_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['supplier_invoice_date' => 'date', 'due_on' => 'date', 'net_amount' => 'decimal:4', 'gross_amount' => 'decimal:4', 'tax_amount' => 'decimal:4', 'rounding_amount' => 'decimal:4'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(PartyRole::class, 'payee_party_role_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceLine::class, 'document_id', 'document_id')->orderBy('line_no');
    }
}

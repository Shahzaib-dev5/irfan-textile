<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesCreditNote extends InventoryDocumentPart
{
    protected $table = 'sales_credit_notes';

    protected $primaryKey = 'document_id';

    public $incrementing = false;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesCreditNoteLine::class, 'document_id', 'document_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id', 'document_id');
    }

    protected function casts(): array
    {
        return ['net_amount' => 'decimal:4', 'gross_amount' => 'decimal:4'];
    }
}

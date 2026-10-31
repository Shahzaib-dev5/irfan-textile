<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Document extends InventoryRecord
{
    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (Document $document) {
            if (in_array($document->getOriginal('status'), ['posted', 'reversed', 'cancelled'], true)) {
                $allowed = ['status', 'reason', 'reversed_by', 'reversed_at', 'lock_version', 'updated_at'];
                if ($document->getOriginal('status') !== 'posted' || $document->status !== 'reversed' || array_diff(array_keys($document->getDirty()), $allowed)) {
                    throw new \LogicException('Posted document payload is immutable.');
                }
            }
        });
    }

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['document_date' => 'date', 'posted_at' => 'datetime', 'reversed_at' => 'datetime', 'lock_version' => 'integer'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentStatusEvent::class);
    }

    public function adjustment(): HasOne
    {
        return $this->hasOne(InventoryAdjustment::class);
    }

    public function transfer(): HasOne
    {
        return $this->hasOne(InventoryTransfer::class);
    }

    public function journal(): HasOne
    {
        return $this->hasOne(JournalEntry::class);
    }
}

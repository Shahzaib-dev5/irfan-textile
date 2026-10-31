<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class VendorServiceRate extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'unit_rate' => 'decimal:8', 'created_at' => 'datetime'];
    }

    public function billingUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'billing_unit_id');
    }

    public function vendorService(): BelongsTo
    {
        return $this->belongsTo(VendorService::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Published vendor rates are immutable.'));
        static::deleting(fn () => throw new LogicException('Published vendor rates are append-only.'));
    }
}

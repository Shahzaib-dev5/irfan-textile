<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessingService extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function billingUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'billing_unit_id');
    }

    public function vendorServices(): HasMany
    {
        return $this->hasMany(VendorService::class);
    }
}

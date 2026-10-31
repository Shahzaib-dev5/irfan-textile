<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorService extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_party_role_id', 'party_role_id');
    }

    public function processingService(): BelongsTo
    {
        return $this->belongsTo(ProcessingService::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(VendorServiceRate::class);
    }
}

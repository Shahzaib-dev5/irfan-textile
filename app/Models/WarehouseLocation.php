<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseLocation extends InventoryRecord
{
    public function getDisplayNameAttribute(): string
    {
        return $this->location_type === 'contractor'
            ? 'With vendor: '.($this->contractor?->role?->party?->legal_name ?? 'Vendor custody')
            : $this->warehouse->name;
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'contractor_vendor_party_role_id', 'party_role_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(StockPosition::class);
    }
}

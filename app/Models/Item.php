<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Item extends CatalogMaster
{
    public const TYPES = ['grey_fabric' => 'Raw grey / kora fabric', 'processed_fabric' => 'Processed fabric', 'trim' => 'Material / consumable', 'packaging' => 'Packaging material', 'finished' => 'Finished product', 'scrap' => 'Scrap', 'service' => 'Nonstock service'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_stocked' => 'boolean', 'track_rolls' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function fabricSpecification(): HasOne
    {
        return $this->hasOne(FabricSpecification::class);
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(ItemUnitConversion::class);
    }

    public function variant(): HasOne
    {
        return $this->hasOne(ProductVariant::class);
    }

    public function isFabric(): bool
    {
        return in_array($this->item_type, ['grey_fabric', 'processed_fabric'], true);
    }
}

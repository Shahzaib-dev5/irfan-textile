<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends CatalogMaster
{
    protected $guarded = ['id'];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'base_unit_id');
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(ItemUnitConversion::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'decimal_places' => 'integer'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Color extends CatalogMaster
{
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}

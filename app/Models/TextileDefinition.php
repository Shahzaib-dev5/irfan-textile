<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class TextileDefinition extends CatalogMaster
{
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}

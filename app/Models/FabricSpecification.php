<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FabricSpecification extends CatalogMaster
{
    protected $primaryKey = 'item_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['width_mm' => 'decimal:3', 'gsm' => 'decimal:3'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}

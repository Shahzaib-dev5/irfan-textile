<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

abstract class InventoryRecord extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'roll_key'];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new \LogicException('Inventory records are retained for audit.');
        });
    }
}

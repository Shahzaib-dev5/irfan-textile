<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartyRoleType extends Model
{
    protected $guarded = ['id'];

    public function roles(): HasMany
    {
        return $this->hasMany(PartyRole::class);
    }
}

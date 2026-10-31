<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorProfile extends Model
{
    protected $primaryKey = 'party_role_id';

    public $incrementing = false;

    protected $guarded = [];

    public function role(): BelongsTo
    {
        return $this->belongsTo(PartyRole::class, 'party_role_id');
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(VendorService::class, 'vendor_party_role_id', 'party_role_id');
    }
}

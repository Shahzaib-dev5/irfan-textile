<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProfile extends Model
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
}

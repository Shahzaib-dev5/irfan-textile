<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProfile extends Model
{
    protected $primaryKey = 'party_role_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['credit_limit' => 'decimal:4', 'credit_hold' => 'boolean'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(PartyRole::class, 'party_role_id');
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }
}

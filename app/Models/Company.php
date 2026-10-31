<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $table = 'companies';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'legal_name', 'trading_name', 'ntn', 'sales_tax_registration',
        'address_text', 'phone', 'email', 'functional_currency', 'timezone',
        'fiscal_year_start_month',
    ];
}

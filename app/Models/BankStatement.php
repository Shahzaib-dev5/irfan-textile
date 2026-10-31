<?php

namespace App\Models;

class BankStatement extends InventoryHistory
{
    protected $table = 'bank_statements';

    public const UPDATED_AT = 'updated_at';
}

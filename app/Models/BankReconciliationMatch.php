<?php

namespace App\Models;

class BankReconciliationMatch extends InventoryHistory
{
    protected $table = 'bank_reconciliation_matches';

    public const UPDATED_AT = 'updated_at';
}

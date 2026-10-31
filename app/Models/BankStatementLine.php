<?php

namespace App\Models;

class BankStatementLine extends InventoryHistory
{
    protected $table = 'bank_statement_lines';

    public const UPDATED_AT = 'updated_at';
}

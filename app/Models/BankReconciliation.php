<?php

namespace App\Models;

class BankReconciliation extends InventoryDocumentPart
{
    protected $table = 'bank_reconciliations';

    protected $primaryKey = 'document_id';

    public $incrementing = false;
}

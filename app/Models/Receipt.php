<?php

namespace App\Models;

class Receipt extends InventoryDocumentPart
{
    protected $table = 'receipts';

    protected $primaryKey = 'document_id';

    public $incrementing = false;
}

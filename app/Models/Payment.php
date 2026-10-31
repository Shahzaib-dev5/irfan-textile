<?php

namespace App\Models;

class Payment extends InventoryDocumentPart
{
    protected $table = 'payments';

    protected $primaryKey = 'document_id';

    public $incrementing = false;
}

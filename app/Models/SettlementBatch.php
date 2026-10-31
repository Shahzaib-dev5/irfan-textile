<?php

namespace App\Models;

class SettlementBatch extends InventoryDocumentPart
{
    protected $table = 'settlement_batches';

    protected $primaryKey = 'document_id';

    public $incrementing = false;
}

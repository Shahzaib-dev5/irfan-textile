<?php

namespace App\Models;

class PackingOrder extends InventoryDocumentPart
{
    protected $primaryKey = 'document_id';

    public $incrementing = false;

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function spec()
    {
        return $this->belongsTo(ProductPackSpec::class, 'product_pack_spec_id');
    }

    public function materials()
    {
        return $this->hasMany(PackingMaterialLine::class, 'document_id', 'document_id');
    }

    public function outputs()
    {
        return $this->hasMany(PackingOutputLine::class, 'document_id', 'document_id');
    }
}

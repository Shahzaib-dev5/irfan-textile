<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessingFabricFlow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function ($flow) {
            if ($flow->getOriginal('status') === 'posted') {
                throw new \LogicException('Confirmed fabric flow details are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Fabric flow history cannot be deleted.'));
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function onwardDocument()
    {
        return $this->belongsTo(Document::class, 'onward_document_id');
    }
}

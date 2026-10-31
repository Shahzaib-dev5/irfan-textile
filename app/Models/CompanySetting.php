<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    protected $fillable = ['setting_key', 'value_json', 'updated_by'];

    protected function casts(): array
    {
        return ['value_json' => 'array'];
    }
}

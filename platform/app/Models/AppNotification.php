<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['workspace_id', 'person_key', 'dedupe_key', 'text', 'ref', 'read_at', 'emailed_at'];

    protected function casts(): array
    {
        return ['ref' => 'array', 'read_at' => 'datetime', 'emailed_at' => 'datetime'];
    }
}

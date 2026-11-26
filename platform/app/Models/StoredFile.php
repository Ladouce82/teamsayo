<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StoredFile extends Model
{
    protected $fillable = ['workspace_id', 'user_id', 'name', 'mime', 'size', 'path'];

    protected static function booted(): void
    {
        static::creating(fn (StoredFile $f) => $f->uuid = $f->uuid ?: (string) Str::uuid());
    }
}

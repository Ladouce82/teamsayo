<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $fillable = ['provider', 'event_id', 'type', 'payload', 'processed_at', 'error'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}

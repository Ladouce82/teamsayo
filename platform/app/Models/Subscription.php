<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = ['workspace_id', 'provider', 'provider_id', 'plan_code', 'plan', 'cycle', 'status', 'quantity', 'current_period_end', 'cancelled_at', 'raw'];

    protected function casts(): array
    {
        return ['current_period_end' => 'datetime', 'cancelled_at' => 'datetime', 'raw' => 'array'];
    }
}

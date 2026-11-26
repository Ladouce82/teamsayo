<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = ['workspace_id', 'subscription_id', 'provider_txn_id', 'invoice_number', 'amount', 'currency', 'status', 'paid_at', 'raw'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'raw' => 'array', 'amount' => 'decimal:2'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Workspace extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'owner_id', 'plan', 'cycle', 'status', 'trial_ends_at', 'seats', 'nonprofit', 'sector', 'settings'];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'nonprofit' => 'boolean',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Workspace $w) {
            $w->uuid = $w->uuid ?: (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AppItem::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Active paid subscription or a running trial. */
    public function isActive(): bool
    {
        if (in_array($this->status, ['active', 'past_due'], true)) {
            return true;
        }

        return $this->status === 'trialing' && $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /** Workspaces without a running trial or subscription become read-only (data is kept). */
    public function isReadOnly(): bool
    {
        return ! $this->isActive();
    }

    public function seatsUsed(): int
    {
        return $this->memberships()->count()
            + $this->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count();
    }

    /** Seats available for new members. During the trial up to 25 people can be invited. */
    public function seatLimit(): int
    {
        return $this->status === 'trialing' ? 25 : max($this->seats, 1);
    }
}

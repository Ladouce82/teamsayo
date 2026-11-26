<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Membership extends Model
{
    public const ROLES = ['owner', 'admin', 'director', 'manager', 'employee', 'auditor'];

    /** Roles that may be granted through invitations or role changes (owner is transferred separately). */
    public const ASSIGNABLE = ['admin', 'director', 'manager', 'employee', 'auditor'];

    protected $fillable = ['workspace_id', 'user_id', 'role', 'company_id', 'person_key', 'title', 'manager_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'manager_id');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members')->withPivot('is_lead');
    }

    /** Role name used inside the TeamSayo app. */
    public function appRole(): string
    {
        return in_array($this->role, ['owner', 'admin'], true) ? 'admin' : $this->role;
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }

    public function seesAllCompanies(): bool
    {
        return in_array($this->role, ['owner', 'admin', 'auditor'], true) || $this->company_id === null;
    }

    public function canManageMembers(): bool
    {
        return in_array($this->role, ['owner', 'admin', 'director'], true);
    }

    public function canManageBilling(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }
}

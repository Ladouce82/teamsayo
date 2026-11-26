<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Team extends Model
{
    protected $fillable = ['workspace_id', 'company_id', 'name'];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Membership::class, 'team_members')->withPivot('is_lead');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

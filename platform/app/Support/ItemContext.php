<?php

namespace App\Support;

use App\Models\AppItem;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Workspace;

/**
 * Everything the permission rules need to know about the signed-in member and the workspace,
 * loaded once per request.
 */
class ItemContext
{
    public string $role;
    public string $me;
    /** @var array<string,string> person key => manager person key */
    public array $managerOf = [];
    /** @var array<string,?int> person key => company id */
    public array $personCompany = [];
    /** @var array<string,int> company key => company id */
    public array $companyIds = [];
    /** @var string[] record types flagged as sensitive */
    public array $sensitiveTypes = ['attendance', 'capacity', 'leave'];
    /** @var array<string,int> person key => number of 360° responses about them */
    public array $feedbackCount = [];

    public function __construct(public Workspace $workspace, public Membership $membership)
    {
        $this->role = $membership->role;
        $this->me = $membership->person_key;

        $this->companyIds = Company::where('workspace_id', $workspace->id)->pluck('id', 'key')->all();

        $people = AppItem::where('workspace_id', $workspace->id)->where('collection', 'people')->get(['item_key', 'data', 'company_id']);
        foreach ($people as $p) {
            $this->managerOf[$p->item_key] = (string) ($p->data['manager'] ?? '');
            $this->personCompany[$p->item_key] = $p->company_id;
        }
        // Manager relationships set on logins take precedence over the editable person record.
        $members = Membership::where('workspace_id', $workspace->id)->get(['id', 'person_key', 'manager_id', 'company_id']);
        $keyById = $members->pluck('person_key', 'id')->all();
        foreach ($members as $m) {
            if ($m->manager_id && isset($keyById[$m->manager_id])) {
                $this->managerOf[$m->person_key] = $keyById[$m->manager_id];
            }
            $this->personCompany[$m->person_key] ??= $m->company_id;
        }

        $types = AppItem::where('workspace_id', $workspace->id)->where('collection', 'recordTypes')->get(['item_key', 'data']);
        foreach ($types as $t) {
            if (! empty($t->data['sensitive'])) {
                $this->sensitiveTypes[] = $t->item_key;
            }
        }

        $fb = AppItem::where('workspace_id', $workspace->id)->where('collection', 'feedback')->get(['data']);
        foreach ($fb as $f) {
            $about = (string) ($f->data['about'] ?? '');
            $this->feedbackCount[$about] = ($this->feedbackCount[$about] ?? 0) + 1;
        }
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }

    public function isEditor(): bool
    {
        return in_array($this->role, ['owner', 'admin', 'director', 'manager'], true);
    }

    public function seesAll(): bool
    {
        return $this->membership->seesAllCompanies();
    }

    public function reportsToMe(?string $person): bool
    {
        return $person !== null && $person !== '' && ($this->managerOf[$person] ?? '') === $this->me;
    }

    public function companyIdFromKey(?string $key): ?int
    {
        return $key !== null && $key !== '' ? ($this->companyIds[$key] ?? null) : null;
    }
}

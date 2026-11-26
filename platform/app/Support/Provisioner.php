<?php

namespace App\Support;

use App\Models\AppItem;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Creates workspaces, companies and members, keeping the app's person and company records in step. */
class Provisioner
{
    private const COLORS = ['#0fa3a3', '#7a5af8', '#f79009', '#2e90fa', '#12b76a', '#ee46bc', '#6172f3', '#f04438'];

    public static function newPersonKey(): string
    {
        return 'p_' . Str::lower(Str::random(12));
    }

    /**
     * @param  string[]  $companyNames
     */
    public static function createWorkspace(User $owner, string $name, array $companyNames, ?string $sector = null, bool $nonprofit = false): Workspace
    {
        return DB::transaction(function () use ($owner, $name, $companyNames, $sector, $nonprofit) {
            $workspace = Workspace::create([
                'name' => $name,
                'owner_id' => $owner->id,
                'plan' => 'trial',
                'status' => 'trialing',
                'trial_ends_at' => now()->addDays(config('teamsayo.trial_days')),
                'nonprofit' => $nonprofit,
                'sector' => $sector,
                'settings' => ['pack' => $sector],
            ]);

            $companyNames = array_values(array_filter(array_map('trim', $companyNames))) ?: [$name];
            $first = null;
            foreach (array_slice($companyNames, 0, 50) as $i => $companyName) {
                $company = self::createCompany($workspace, $companyName, $i, $owner);
                $first ??= $company;
            }

            self::addMember($workspace, $owner, 'owner', $first->id, self::newPersonKey(), 'Workspace owner');
            AuditLog::record('Workspace created', $name . ($sector ? " · {$sector}" : ''), $workspace->id, $owner->id);
            $owner->forceFill(['current_workspace_id' => $workspace->id])->save();

            return $workspace;
        });
    }

    public static function createCompany(Workspace $workspace, string $name, int $index = 0, ?User $by = null, ?string $key = null, ?string $color = null): Company
    {
        $company = Company::create([
            'workspace_id' => $workspace->id,
            'key' => $key ?: 'c_' . Str::lower(Str::random(10)),
            'name' => mb_substr($name, 0, 120),
            'color' => $color ?: self::COLORS[$index % count(self::COLORS)],
        ]);
        self::syncCompanyItem($company, $by);

        return $company;
    }

    public static function syncCompanyItem(Company $company, ?User $by = null): void
    {
        $item = AppItem::withTrashed()->firstOrNew(['workspace_id' => $company->workspace_id, 'collection' => 'orgs', 'item_key' => $company->key]);
        $item->fill([
            'company_id' => $company->id,
            'data' => ['id' => $company->key, 'name' => $company->name, 'color' => $company->color],
            'version' => ($item->version ?? 0) + 1,
            'created_by' => $item->created_by ?? $by?->id,
            'updated_by' => $by?->id,
        ]);
        $item->deleted_at = null;
        $item->save();
    }

    public static function addMember(Workspace $workspace, User $user, string $role, ?int $companyId, string $personKey, ?string $title = null): Membership
    {
        $membership = Membership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'company_id' => $companyId,
            'person_key' => $personKey,
            'title' => $title,
        ]);
        self::ensurePersonItem($membership, $user);

        return $membership;
    }

    /** Creates or updates the app's person record for a login so the person appears in the team. */
    public static function ensurePersonItem(Membership $membership, ?User $user = null, array $extra = []): void
    {
        $user ??= $membership->user;
        $company = $membership->company_id ? Company::find($membership->company_id) : null;
        $item = AppItem::withTrashed()->firstOrNew(['workspace_id' => $membership->workspace_id, 'collection' => 'people', 'item_key' => $membership->person_key]);
        $data = is_array($item->data) ? $item->data : [];
        $data = array_merge([
            'id' => $membership->person_key,
            'name' => $user->name,
            'title' => $membership->title ?: ($data['title'] ?? ''),
            'org' => $company?->key ?? ($data['org'] ?? null),
            'team' => $data['team'] ?? '',
            'manager' => $data['manager'] ?? '',
        ], $data, $extra, [
            'email' => $user->email,
            'role' => $membership->appRole(),
            'mid' => $membership->id,
            'invited' => false,
        ]);
        if (! ($data['name'] ?? '')) {
            $data['name'] = $user->name;
        }
        $item->fill([
            'company_id' => $company?->id,
            'subject_key' => $membership->person_key,
            'data' => $data,
            'version' => ($item->version ?? 0) + 1,
        ]);
        $item->deleted_at = null;
        $item->save();
    }

    /** A person who has been invited but has not signed in yet. */
    public static function ensureInvitedPerson(Workspace $workspace, string $personKey, string $email, string $name, string $role, ?Company $company, ?string $title): void
    {
        $item = AppItem::withTrashed()->firstOrNew(['workspace_id' => $workspace->id, 'collection' => 'people', 'item_key' => $personKey]);
        $data = is_array($item->data) ? $item->data : [];
        $data = array_merge(['id' => $personKey, 'name' => $name, 'title' => $title ?? '', 'org' => $company?->key, 'team' => '', 'manager' => ''], $data, [
            'email' => $email,
            'role' => in_array($role, ['owner', 'admin'], true) ? 'admin' : $role,
            'invited' => true,
        ]);
        $item->fill(['company_id' => $company?->id, 'subject_key' => $personKey, 'data' => $data, 'version' => ($item->version ?? 0) + 1]);
        $item->deleted_at = null;
        $item->save();
    }
}

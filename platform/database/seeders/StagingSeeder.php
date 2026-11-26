<?php

namespace Database\Seeders;

use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Provisioner;
use Illuminate\Database\Seeder;

/**
 * Private test accounts for staging only (one per role). They never exist in production.
 * Password: the TEST_ACCOUNTS_PASSWORD secret. Re-running is safe.
 */
class StagingSeeder extends Seeder
{
    public const ACCOUNTS = [
        ['owner@staging.teamsayo.com', 'Test Owner', 'owner', 0, 'Group owner'],
        ['director@staging.teamsayo.com', 'Test Director', 'director', 0, 'Studio director'],
        ['manager@staging.teamsayo.com', 'Test Manager', 'manager', 0, 'Team lead'],
        ['employee@staging.teamsayo.com', 'Test Employee', 'employee', 0, 'Designer'],
        ['auditor@staging.teamsayo.com', 'Test Auditor', 'auditor', null, 'External auditor'],
        ['director2@staging.teamsayo.com', 'Test Director Two', 'director', 1, 'Managing director'],
    ];

    public function run(): void
    {
        if (! app()->environment(['staging', 'local', 'testing'])) {
            $this->command?->error('StagingSeeder only runs on staging.');

            return;
        }
        $password = config('teamsayo.test_accounts_password');
        if (! $password) {
            $this->command?->warn('TEST_ACCOUNTS_PASSWORD is not set; no test accounts created.');

            return;
        }
        $users = [];
        foreach (self::ACCOUNTS as [$email, $name]) {
            $u = User::firstOrNew(['email' => $email]);
            $u->fill(['name' => $name, 'password' => $password]);
            $u->email_verified_at ??= now();
            $u->save();
            $users[$email] = $u;
        }
        $users['owner@staging.teamsayo.com']->forceFill(['is_staff' => true])->save();

        $workspace = Workspace::where('owner_id', $users['owner@staging.teamsayo.com']->id)->where('name', 'TeamSayo Test Group')->first()
            ?? Provisioner::createWorkspace($users['owner@staging.teamsayo.com'], 'TeamSayo Test Group', ['Glimmericons', 'Blink Icons', 'Digitaleicons', 'Avalanche Icons'], 'Creative & digital studio');
        $workspace->update(['status' => 'trialing', 'trial_ends_at' => now()->addYears(5)]);
        $companies = $workspace->companies()->orderBy('id')->get();
        $managerId = null;
        foreach (self::ACCOUNTS as [$email, , $role, $companyIdx, $title]) {
            $u = $users[$email];
            $m = Membership::where('workspace_id', $workspace->id)->where('user_id', $u->id)->first()
                ?? Provisioner::addMember($workspace, $u, $role, $companyIdx === null ? null : $companies[$companyIdx]->id, Provisioner::newPersonKey(), $title);
            if ($role === 'manager') {
                $managerId = $m->id;
            }
            if ($role === 'employee' && $managerId) {
                $m->update(['manager_id' => $managerId]);
                Provisioner::ensurePersonItem($m->fresh(), $u, ['manager' => Membership::find($managerId)->person_key]);
            }
        }
        $this->command?->info('Staging test accounts ready in "TeamSayo Test Group".');
    }
}

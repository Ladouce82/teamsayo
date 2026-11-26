<?php

namespace Tests;

use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Provisioner;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** A workspace with two companies and one member per role. */
    protected function workspace(): array
    {
        $owner = User::factory()->create(['name' => 'Olga Owner']);
        $ws = Provisioner::createWorkspace($owner, 'Nordlicht', ['Kiel', 'Hamburg'], 'Consultancy');
        [$kiel, $hh] = $ws->companies()->orderBy('id')->get()->all();
        $u = ['owner' => $owner];
        $m = ['owner' => Membership::where('user_id', $owner->id)->first()];
        foreach ([['director', $kiel], ['manager', $kiel], ['employee', $kiel], ['employee2', $kiel], ['manager_hh', $hh], ['auditor', null]] as [$name, $co]) {
            $role = preg_replace('/\d|_hh/', '', $name);
            $u[$name] = User::factory()->create(['name' => ucfirst($name)]);
            $m[$name] = Provisioner::addMember($ws, $u[$name], $role, $co?->id, Provisioner::newPersonKey());
        }
        $m['employee']->update(['manager_id' => $m['manager']->id]);
        Provisioner::ensurePersonItem($m['employee']->fresh(), $u['employee'], ['manager' => $m['manager']->person_key]);

        return [$ws->fresh(), $u, $m, $kiel, $hh];
    }

    protected function sync(User $user, Workspace $ws, array $upserts = [], array $deletes = [])
    {
        return $this->actingAs($user)->postJson("/api/w/{$ws->uuid}/sync", ['upserts' => $upserts, 'deletes' => $deletes]);
    }

    protected function items(User $user, Workspace $ws): array
    {
        return collect($this->actingAs($user)->getJson("/api/w/{$ws->uuid}/items")->assertOk()->json('items'))
            ->mapWithKeys(fn ($i) => [$i['c'] . ':' . $i['k'] => $i])->all();
    }
}

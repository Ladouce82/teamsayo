<?php

namespace Tests\Feature;

use App\Models\AppItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_records_are_only_visible_to_the_person_their_manager_and_leaders(): void
    {
        [$ws, $u, $m, $kiel, $hh] = $this->workspace();
        $emp = $m['employee']->person_key;
        $this->sync($u['owner'], $ws, [
            ['c' => 'att', 'k' => "S5|{$emp}", 'd' => ["{$emp}|d1" => 'P']],
            ['c' => 'records', 'k' => 'r1', 'd' => ['id' => 'r1', 'type' => 'leave', 'org' => $kiel->key, 'fields' => ['person' => $emp, 'days' => 2]]],
            ['c' => 'records', 'k' => 'r2', 'd' => ['id' => 'r2', 'type' => 'photos', 'org' => $kiel->key, 'fields' => ['person' => $emp]]],
        ])->assertOk()->assertJsonCount(3, 'ok');

        foreach (['owner', 'director', 'manager', 'employee', 'auditor'] as $who) {
            $items = $this->items($u[$who], $ws);
            $this->assertArrayHasKey("att:S5|{$emp}", $items, $who);
            $this->assertArrayHasKey('records:r1', $items, $who);
        }
        foreach (['employee2', 'manager_hh'] as $who) {
            $items = $this->items($u[$who], $ws);
            $this->assertArrayNotHasKey("att:S5|{$emp}", $items, $who);
            $this->assertArrayNotHasKey('records:r1', $items, $who);
        }
        $this->assertArrayHasKey('records:r2', $this->items($u['employee2'], $ws));          // not sensitive, same company
        $this->assertArrayNotHasKey('records:r2', $this->items($u['manager_hh'], $ws));     // other company
    }

    public function test_employees_can_only_change_their_own_records(): void
    {
        [$ws, $u, $m, $kiel, $hh] = $this->workspace();
        $emp = $m['employee']->person_key;
        $other = $m['employee2']->person_key;
        $r = $this->sync($u['employee'], $ws, [
            ['c' => 'actions', 'k' => 'a1', 'd' => ['id' => 'a1', 'title' => 'Mine', 'owner' => $emp, 'org' => $kiel->key]],
            ['c' => 'actions', 'k' => 'a2', 'd' => ['id' => 'a2', 'title' => 'Theirs', 'owner' => $other, 'org' => $kiel->key]],
            ['c' => 'rules', 'k' => 'r1', 'd' => ['id' => 'r1', 'on' => false]],
            ['c' => 'orgs', 'k' => 'evil', 'd' => ['id' => 'evil', 'name' => 'Evil']],
        ])->assertOk();
        $this->assertSame(['a1'], collect($r->json('ok'))->pluck('k')->all());
        $this->assertEqualsCanonicalizing(['a2', 'r1', 'evil'], collect($r->json('rejected'))->pluck('k')->all());
        $this->assertSame(2, $ws->companies()->count());
    }

    public function test_nobody_can_change_roles_or_emails_through_the_app(): void
    {
        [$ws, $u, $m, $kiel, $hh] = $this->workspace();
        $emp = $m['employee']->person_key;
        $item = AppItem::where('collection', 'people')->where('item_key', $emp)->first();
        $d = $item->data;
        $this->sync($u['employee'], $ws, [['c' => 'people', 'k' => $emp, 'v' => $item->version, 'd' => array_merge($d, ['role' => 'admin', 'name' => 'Boss', 'email' => 'x@evil.test'])]])->assertOk();
        $after = $item->fresh()->data;
        $this->assertSame('employee', $after['role']);
        $this->assertSame($d['name'], $after['name']);           // employees may not rename themselves
        $this->assertSame($u['employee']->email, $after['email']);

        $this->sync($u['director'], $ws, [['c' => 'people', 'k' => $emp, 'v' => $item->fresh()->version, 'd' => array_merge($after, ['role' => 'admin', 'title' => 'Lead'])]])->assertOk();
        $this->assertSame('employee', $item->fresh()->data['role']);
        $this->assertSame('Lead', $item->fresh()->data['title']);
    }

    public function test_auditors_are_read_only_and_versions_prevent_lost_updates(): void
    {
        [$ws, $u, $m, $kiel, $hh] = $this->workspace();
        $this->sync($u['auditor'], $ws, [['c' => 'goals', 'k' => 'g1', 'd' => ['id' => 'g1', 'title' => 'x']]])->assertJsonPath('rejected.0.reason', 'forbidden');

        $this->sync($u['director'], $ws, [['c' => 'goals', 'k' => 'g1', 'd' => ['id' => 'g1', 'title' => 'v1', 'org' => $kiel->key]]])->assertJsonPath('ok.0.v', 1);
        $this->sync($u['manager'], $ws, [['c' => 'goals', 'k' => 'g1', 'v' => 1, 'd' => ['id' => 'g1', 'title' => 'manager', 'org' => $kiel->key]]])->assertJsonPath('ok.0.v', 2);
        $this->sync($u['director'], $ws, [['c' => 'goals', 'k' => 'g1', 'v' => 1, 'd' => ['id' => 'g1', 'title' => 'stale', 'org' => $kiel->key]]])
            ->assertJsonPath('rejected.0.reason', 'conflict')->assertJsonPath('rejected.0.d.title', 'manager');
    }

    public function test_managers_cannot_move_records_into_another_company(): void
    {
        [$ws, $u, $m, $kiel, $hh] = $this->workspace();
        $this->sync($u['manager'], $ws, [['c' => 'meetings', 'k' => 'm1', 'd' => ['id' => 'm1', 'title' => 'x', 'org' => $hh->key]]]);
        $this->assertFalse(AppItem::where('item_key', 'm1')->exists());
    }

    public function test_deleted_items_are_reported_to_other_browsers(): void
    {
        [$ws, $u, $m, $kiel, $hh] = $this->workspace();
        $this->sync($u['owner'], $ws, [['c' => 'kudos', 'k' => 'k1', 'd' => ['id' => 'k1', 'msg' => 'Thanks']]]);
        $since = now()->subMinute()->toIso8601String();
        $this->sync($u['owner'], $ws, [], [['c' => 'kudos', 'k' => 'k1']])->assertJsonPath('ok.0.deleted', true);
        $this->actingAs($u['employee'])->getJson("/api/w/{$ws->uuid}/items?since=" . urlencode($since))->assertJsonFragment(['c' => 'kudos', 'k' => 'k1']);
    }

    public function test_people_with_a_login_cannot_be_deleted_from_the_app(): void
    {
        [$ws, $u, $m, $kiel, $hh] = $this->workspace();
        $this->sync($u['owner'], $ws, [], [['c' => 'people', 'k' => $m['employee']->person_key]])->assertJsonPath('rejected.0.reason', 'remove_member_first');
    }
}

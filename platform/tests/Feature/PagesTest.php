<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_render(): void
    {
        foreach (['/login', '/register', '/forgot-password', '/legal/imprint', '/legal/privacy', '/legal/terms', '/invitations/not-a-token'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/legal/other')->assertNotFound();
    }

    public function test_signed_in_pages_render_for_owner(): void
    {
        [$ws, $u] = $this->workspace();
        $u['owner']->forceFill(['is_staff' => true])->save();
        $this->actingAs($u['owner']);
        foreach (["/w/{$ws->uuid}/members", "/w/{$ws->uuid}/billing", "/w/{$ws->uuid}/settings", '/account', '/onboarding', '/admin'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get("/w/{$ws->uuid}/export")->assertOk();
        $this->post('/account/two-factor', ['password' => 'password'])->assertRedirect();
        $this->get('/account')->assertOk()->assertSee('<svg', false);
    }

    public function test_non_staff_cannot_open_the_operator_console(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_director_sees_members_page(): void
    {
        [$ws, $u] = $this->workspace();
        $this->actingAs($u['director'])->get("/w/{$ws->uuid}/members")->assertOk()->assertDontSee('Billing</a>', false);
    }

    public function test_staging_seeder_creates_private_test_accounts(): void
    {
        config(['teamsayo.test_accounts_password' => 'Staging-Test-123']);
        $this->seed(\Database\Seeders\StagingSeeder::class);
        $this->seed(\Database\Seeders\StagingSeeder::class);   // safe to re-run
        $this->assertSame(6, User::where('email', 'like', '%@staging.teamsayo.com')->count());
        $this->post('/login', ['email' => 'employee@staging.teamsayo.com', 'password' => 'Staging-Test-123'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_rules_command_sends_due_reminders_once(): void
    {
        [$ws, $u, $m] = $this->workspace();
        $emp = $m['employee']->person_key;
        $this->sync($u['owner'], $ws, [
            ['c' => 'rules', 'k' => 'r1', 'd' => ['id' => 'r1', 'on' => true, 'trigger' => 'due_soon', 'days' => 2]],
            ['c' => 'actions', 'k' => 'a1', 'd' => ['id' => 'a1', 'title' => 'Send report', 'owner' => $emp, 'due' => now()->addDay()->toDateString(), 'status' => 'open']],
        ])->assertOk();
        $this->artisan('teamsayo:run-rules')->assertSuccessful();
        $this->artisan('teamsayo:run-rules')->assertSuccessful();
        $n = $this->actingAs($u['employee'])->getJson("/api/w/{$ws->uuid}/notifications")->json('notifications');
        $this->assertCount(1, collect($n)->filter(fn ($x) => str_contains($x['text'], 'Send report')));
    }
}

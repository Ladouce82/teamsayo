<?php

namespace Tests\Feature;

use App\Models\AppItem;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Totp;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_sign_up_verify_and_create_a_workspace(): void
    {
        Notification::fake();
        $this->post('/register', ['name' => 'Ana Admin', 'email' => 'Ana@Example.com', 'password' => 'correct-horse-42', 'password_confirmation' => 'correct-horse-42', 'terms' => '1'])
            ->assertRedirect(route('verification.notice'));
        $user = User::where('email', 'ana@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->get('/')->assertRedirect(route('verification.notice'));
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($url)->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $this->get('/')->assertRedirect(route('onboarding'));
        $this->post('/onboarding', ['workspace' => 'Nordlicht Group', 'companies' => "Nordlicht Kiel\nNordlicht Hamburg", 'sector' => 'Consultancy', 'nonprofit' => '1'])->assertRedirect();

        $ws = Workspace::firstOrFail();
        $this->assertSame('trialing', $ws->status);
        $this->assertTrue($ws->nonprofit);
        $this->assertSame(2, $ws->companies()->count());
        $this->assertSame('owner', Membership::where('user_id', $user->id)->value('role'));
        $this->assertSame(2, AppItem::where('workspace_id', $ws->id)->where('collection', 'orgs')->count());
        $this->assertSame(1, AppItem::where('workspace_id', $ws->id)->where('collection', 'people')->count());
        $this->get('/')->assertRedirect(route('app', $ws));
    }

    public function test_weak_passwords_and_missing_terms_are_rejected(): void
    {
        $this->post('/register', ['name' => 'A', 'email' => 'a@example.com', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors(['password', 'terms']);
        $this->assertSame(0, User::count());
    }

    public function test_login_with_two_factor(): void
    {
        $user = User::factory()->create(['email' => 'tf@example.com']);
        $secret = Totp::generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        $this->post('/login', ['email' => 'tf@example.com', 'password' => 'password'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->post('/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/two-factor', ['code' => Totp::code($secret)])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_failed_logins_are_rate_limited(): void
    {
        User::factory()->create(['email' => 'rl@example.com']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'rl@example.com', 'password' => 'wrong']);
        }
        $this->post('/login', ['email' => 'rl@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_security_headers_are_sent(): void
    {
        $r = $this->get('/login')->assertOk();
        $this->assertStringContainsString("frame-ancestors 'none'", $r->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $r->headers->get('X-Frame-Options'));
    }

    public function test_the_app_is_served_with_the_server_connector(): void
    {
        [$ws, $u] = $this->workspace();
        $r = $this->actingAs($u['employee'])->get("/w/{$ws->uuid}")->assertOk();
        $html = $r->getContent();
        $this->assertStringContainsString('window.TS_BOOT=', $html);
        $this->assertStringContainsString('TS_SERVER', $html);
        $this->assertStringNotContainsString('"role":"admin"', substr($html, strpos($html, 'window.TS_BOOT='), 1500));
        $this->assertSame('no-store', explode(',', $r->headers->get('Cache-Control'))[0]);
    }

    public function test_members_of_other_workspaces_get_404(): void
    {
        [$ws] = $this->workspace();
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get("/w/{$ws->uuid}")->assertNotFound();
        $this->actingAs($stranger)->getJson("/api/w/{$ws->uuid}/items")->assertNotFound();
    }
}

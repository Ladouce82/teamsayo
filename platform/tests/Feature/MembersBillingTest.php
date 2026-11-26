<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\InvitationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MembersBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_invites_a_member_who_joins_with_the_invited_email_only(): void
    {
        Notification::fake();
        [$ws, $u, , $kiel] = $this->workspace();
        $this->actingAs($u['owner'])->post("/w/{$ws->uuid}/members/invite", ['email' => 'New@Example.com', 'name' => 'Nina New', 'role' => 'manager', 'company_id' => $kiel->id])
            ->assertSessionHasNoErrors();
        $token = null;
        Notification::assertSentOnDemand(InvitationMail::class, function ($n) use (&$token) { $token = $n->token; return true; });
        $inv = Invitation::firstOrFail();
        $this->assertSame('new@example.com', $inv->email);
        $this->assertNotSame($token, $inv->token_hash);   // only the hash is stored

        $wrong = User::factory()->create(['email' => 'someone@example.com']);
        $this->actingAs($wrong)->post("/invitations/{$token}")->assertSessionHasErrors('email');

        $right = User::factory()->create(['email' => 'new@example.com']);
        $this->actingAs($right)->post("/invitations/{$token}")->assertRedirect(route('app', $ws));
        $m = Membership::where('user_id', $right->id)->firstOrFail();
        $this->assertSame('manager', $m->role);
        $this->assertSame($kiel->id, $m->company_id);
        $this->assertSame($inv->person_key, $m->person_key);
        $this->actingAs($right)->post("/invitations/{$token}")->assertNotFound();   // single use
    }

    public function test_employees_cannot_manage_members_and_directors_cannot_create_admins(): void
    {
        [$ws, $u] = $this->workspace();
        $this->actingAs($u['employee'])->get("/w/{$ws->uuid}/members")->assertForbidden();
        $this->actingAs($u['director'])->post("/w/{$ws->uuid}/members/invite", ['email' => 'x@example.com', 'role' => 'admin'])->assertSessionHasErrors('role');
        $this->actingAs($u['employee'])->get("/w/{$ws->uuid}/billing")->assertForbidden();
    }

    public function test_seat_limit_is_enforced(): void
    {
        Notification::fake();
        [$ws, $u] = $this->workspace();
        $ws->update(['status' => 'active', 'seats' => 7, 'plan' => 'team', 'cycle' => 'annual']);   // 7 members already
        $this->actingAs($u['owner'])->post("/w/{$ws->uuid}/members/invite", ['email' => 'over@example.com', 'role' => 'employee'])->assertSessionHasErrors('email');
        $this->assertSame(0, Invitation::count());
    }

    public function test_expired_trial_makes_the_workspace_read_only_but_readable(): void
    {
        [$ws, $u] = $this->workspace();
        $ws->update(['trial_ends_at' => now()->subDay()]);
        $this->actingAs($u['employee'])->getJson("/api/w/{$ws->uuid}/items")->assertOk();
        $this->sync($u['owner'], $ws, [['c' => 'kudos', 'k' => 'k1', 'd' => ['id' => 'k1']]])->assertStatus(402);
        $this->artisan('teamsayo:maintenance')->assertSuccessful();
        $this->assertSame('expired', $ws->fresh()->status);
    }

    private function paypal(array $subscription): void
    {
        config(['services.paypal.client_id' => 'cid', 'services.paypal.secret' => 'sec', 'services.paypal.mode' => 'sandbox', 'services.paypal.webhook_id' => 'WH-1']);
        AppSetting::put('paypal_plans_sandbox', ['team_annual' => 'P-TEAM-A', 'business_monthly' => 'P-BIZ-M']);
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
            '*/v1/billing/subscriptions/*' => Http::response($subscription),
        ]);
    }

    public function test_paypal_subscription_activates_the_workspace_and_payments_create_invoices_once(): void
    {
        [$ws, $u] = $this->workspace();
        $sub = ['id' => 'I-SUB1', 'status' => 'ACTIVE', 'plan_id' => 'P-TEAM-A', 'quantity' => '9', 'custom_id' => $ws->uuid, 'billing_info' => ['next_billing_time' => now()->addYear()->toIso8601String()]];
        $this->paypal($sub);

        $this->actingAs($u['owner'])->postJson("/w/{$ws->uuid}/billing/confirm", ['subscription_id' => 'I-SUB1'])->assertJsonPath('ok', true);
        $ws->refresh();
        $this->assertSame(['active', 'team', 'annual', 9], [$ws->status, $ws->plan, $ws->cycle, $ws->seats]);

        $event = ['id' => 'WH-EVT-1', 'event_type' => 'PAYMENT.SALE.COMPLETED', 'resource' => ['id' => 'SALE-1', 'billing_agreement_id' => 'I-SUB1', 'amount' => ['total' => '642.60', 'currency' => 'EUR'], 'state' => 'completed']];
        $this->postJson('/webhooks/paypal', $event)->assertOk();
        $this->postJson('/webhooks/paypal', $event)->assertOk();   // PayPal retry
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, WebhookEvent::count());
        $p = Payment::first();
        $this->assertMatchesRegularExpression('/^TS-\d{4}-00001$/', $p->invoice_number);
        $this->actingAs($u['owner'])->get("/w/{$ws->uuid}/billing/invoices/{$p->id}")->assertOk()->assertSee('642,60');
    }

    public function test_subscriptions_for_another_workspace_are_rejected(): void
    {
        [$ws, $u] = $this->workspace();
        $this->paypal(['id' => 'I-X', 'status' => 'ACTIVE', 'plan_id' => 'P-TEAM-A', 'quantity' => '3', 'custom_id' => 'someone-else']);
        $this->actingAs($u['owner'])->postJson("/w/{$ws->uuid}/billing/confirm", ['subscription_id' => 'I-X'])->assertStatus(422);
        $this->assertSame('trialing', $ws->fresh()->status);
    }

    public function test_webhooks_with_bad_signatures_are_ignored(): void
    {
        [$ws] = $this->workspace();
        config(['services.paypal.client_id' => 'cid', 'services.paypal.secret' => 'sec', 'services.paypal.webhook_id' => 'WH-1']);
        Http::fake(['*/v1/oauth2/token' => Http::response(['access_token' => 't']), '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE'])]);
        $this->postJson('/webhooks/paypal', ['id' => 'E', 'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED', 'resource' => ['id' => 'I-1']])->assertStatus(400);
        $this->assertSame('trialing', $ws->fresh()->status);
    }
}

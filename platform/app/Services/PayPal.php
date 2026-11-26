<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Minimal PayPal REST client for subscriptions (Billing API v1) and webhook verification. */
class PayPal
{
    public function configured(): bool
    {
        return filled(config('services.paypal.client_id')) && filled(config('services.paypal.secret'));
    }

    public function baseUrl(): string
    {
        return config('services.paypal.mode') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function token(): string
    {
        return Cache::remember('paypal_token_' . md5((string) config('services.paypal.client_id')), 3000, function () {
            $r = Http::asForm()->withBasicAuth(config('services.paypal.client_id'), config('services.paypal.secret'))
                ->timeout(20)->post($this->baseUrl() . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);
            if (! $r->successful()) {
                throw new RuntimeException('PayPal authentication failed: ' . $r->status());
            }

            return $r->json('access_token');
        });
    }

    private function http(): PendingRequest
    {
        if (! $this->configured()) {
            throw new RuntimeException('PayPal is not configured.');
        }

        return Http::withToken($this->token())->acceptJson()->asJson()->timeout(25)->baseUrl($this->baseUrl());
    }

    public function createProduct(string $name): array
    {
        return $this->http()->withHeaders(['PayPal-Request-Id' => 'teamsayo-product-' . md5($name)])
            ->post('/v1/catalogs/products', ['name' => $name, 'type' => 'SERVICE', 'category' => 'SOFTWARE'])->throw()->json();
    }

    public function createPlan(string $productId, string $code, string $name, float $unitPrice, string $interval, string $currency, string $taxPercent): array
    {
        return $this->http()->withHeaders(['PayPal-Request-Id' => 'teamsayo-plan-' . $code])->post('/v1/billing/plans', [
            'product_id' => $productId,
            'name' => $name,
            'status' => 'ACTIVE',
            'quantity_supported' => true,
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => $interval, 'interval_count' => 1],
                'tenure_type' => 'REGULAR',
                'sequence' => 1,
                'total_cycles' => 0,
                'pricing_scheme' => ['fixed_price' => ['value' => number_format($unitPrice, 2, '.', ''), 'currency_code' => $currency]],
            ]],
            'payment_preferences' => ['auto_bill_outstanding' => true, 'payment_failure_threshold' => 3],
            'taxes' => ['percentage' => $taxPercent, 'inclusive' => false],
        ])->throw()->json();
    }

    public function createWebhook(string $url): array
    {
        $events = ['BILLING.SUBSCRIPTION.ACTIVATED', 'BILLING.SUBSCRIPTION.UPDATED', 'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.SUSPENDED',
            'BILLING.SUBSCRIPTION.EXPIRED', 'BILLING.SUBSCRIPTION.PAYMENT.FAILED', 'PAYMENT.SALE.COMPLETED', 'PAYMENT.SALE.REFUNDED'];

        return $this->http()->post('/v1/notifications/webhooks', ['url' => $url, 'event_types' => array_map(fn ($e) => ['name' => $e], $events)])->throw()->json();
    }

    public function listWebhooks(): array
    {
        return $this->http()->get('/v1/notifications/webhooks')->throw()->json('webhooks') ?? [];
    }

    public function subscription(string $id): array
    {
        return $this->http()->get('/v1/billing/subscriptions/' . rawurlencode($id))->throw()->json();
    }

    public function cancel(string $id, string $reason): void
    {
        $this->http()->post('/v1/billing/subscriptions/' . rawurlencode($id) . '/cancel', ['reason' => mb_substr($reason, 0, 120)])->throw();
    }

    /** Changes seats or plan. Returns the approval link when PayPal needs the payer to confirm. */
    public function revise(string $id, string $planId, int $quantity, string $returnUrl, string $cancelUrl): ?string
    {
        $r = $this->http()->post('/v1/billing/subscriptions/' . rawurlencode($id) . '/revise', [
            'plan_id' => $planId,
            'quantity' => (string) $quantity,
            'application_context' => ['return_url' => $returnUrl, 'cancel_url' => $cancelUrl, 'brand_name' => 'TeamSayo', 'user_action' => 'CONTINUE'],
        ])->throw()->json();
        foreach ($r['links'] ?? [] as $l) {
            if (($l['rel'] ?? '') === 'approve') {
                return $l['href'];
            }
        }

        return null;
    }

    /** Verifies a webhook really comes from PayPal (signature check done by PayPal's API). */
    public function verifyWebhook(array $headers, array $event, string $webhookId): bool
    {
        $h = fn ($k) => $headers[strtolower($k)][0] ?? $headers[$k][0] ?? null;
        $r = $this->http()->post('/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $h('paypal-auth-algo'),
            'cert_url' => $h('paypal-cert-url'),
            'transmission_id' => $h('paypal-transmission-id'),
            'transmission_sig' => $h('paypal-transmission-sig'),
            'transmission_time' => $h('paypal-transmission-time'),
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ]);

        return $r->successful() && $r->json('verification_status') === 'SUCCESS';
    }
}

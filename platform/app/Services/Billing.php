<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Plans, prices and subscription state for workspaces. */
class Billing
{
    public function __construct(private PayPal $paypal)
    {
    }

    public static function planCode(string $plan, string $cycle, bool $nonprofit): string
    {
        return "{$plan}_{$cycle}" . ($nonprofit ? '_np' : '');
    }

    /** Net price per seat for one billing period. */
    public static function unitPrice(string $plan, string $cycle, bool $nonprofit): float
    {
        $base = (float) config("teamsayo.plans.{$plan}.price");
        $monthly = $base * (1 + (float) config('teamsayo.monthly_premium'));
        $price = $cycle === 'annual' ? $base * 12 : $monthly;
        if ($nonprofit) {
            $price *= 1 - (float) config('teamsayo.nonprofit_discount');
        }

        return round($price, 2);
    }

    /** @return array<string,array{plan:string,cycle:string,nonprofit:bool,price:float}> */
    public static function catalogue(): array
    {
        $out = [];
        foreach (array_keys(config('teamsayo.plans')) as $plan) {
            foreach (['monthly', 'annual'] as $cycle) {
                foreach ([false, true] as $np) {
                    $out[self::planCode($plan, $cycle, $np)] = ['plan' => $plan, 'cycle' => $cycle, 'nonprofit' => $np, 'price' => self::unitPrice($plan, $cycle, $np)];
                }
            }
        }

        return $out;
    }

    public function planIds(): array
    {
        return AppSetting::get('paypal_plans_' . config('services.paypal.mode'), []);
    }

    public function webhookId(): ?string
    {
        return config('services.paypal.webhook_id') ?: AppSetting::get('paypal_webhook_' . config('services.paypal.mode'));
    }

    /** Creates the PayPal product, the plans and the webhook if they do not exist yet. Safe to run on every deploy. */
    public function setup(string $webhookUrl): array
    {
        $mode = config('services.paypal.mode');
        $productId = AppSetting::get("paypal_product_{$mode}") ?: $this->paypal->createProduct('TeamSayo subscription')['id'];
        AppSetting::put("paypal_product_{$mode}", $productId);
        $ids = $this->planIds();
        foreach (self::catalogue() as $code => $p) {
            if (! empty($ids[$code])) {
                continue;
            }
            $label = config("teamsayo.plans.{$p['plan']}.name") . ' · ' . ($p['cycle'] === 'annual' ? 'annual' : 'monthly') . ($p['nonprofit'] ? ' · non-profit' : '') . ' (per user)';
            $ids[$code] = $this->paypal->createPlan($productId, "{$mode}_{$code}", 'TeamSayo ' . $label, $p['price'], $p['cycle'] === 'annual' ? 'YEAR' : 'MONTH', config('teamsayo.currency'), (string) config('teamsayo.tax_percent'))['id'];
            AppSetting::put("paypal_plans_{$mode}", $ids);
        }
        if (! $this->webhookId()) {
            $existing = collect($this->paypal->listWebhooks())->firstWhere('url', $webhookUrl);
            $hook = $existing ?: $this->paypal->createWebhook($webhookUrl);
            AppSetting::put("paypal_webhook_{$mode}", $hook['id']);
        }

        return ['product' => $productId, 'plans' => $ids, 'webhook' => $this->webhookId()];
    }

    /** Applies a PayPal subscription object to our records. */
    public function applySubscription(array $sub, ?Workspace $expected = null): ?Workspace
    {
        $workspace = Workspace::where('uuid', $sub['custom_id'] ?? '')->first();
        if (! $workspace || ($expected && $expected->id !== $workspace->id)) {
            return null;
        }
        $code = array_search($sub['plan_id'] ?? '', $this->planIds(), true);
        if ($code === false) {
            return null;
        }
        $meta = self::catalogue()[$code];
        $status = strtolower($sub['status'] ?? '');
        $next = $sub['billing_info']['next_billing_time'] ?? null;

        DB::transaction(function () use ($workspace, $sub, $code, $meta, $status, $next) {
            Subscription::updateOrCreate(['provider_id' => $sub['id']], [
                'workspace_id' => $workspace->id, 'provider' => 'paypal', 'plan_code' => $code, 'plan' => $meta['plan'], 'cycle' => $meta['cycle'],
                'status' => $status, 'quantity' => (int) ($sub['quantity'] ?? 1), 'current_period_end' => $next ? Carbon::parse($next) : null,
                'cancelled_at' => in_array($status, ['cancelled', 'expired'], true) ? now() : null, 'raw' => $sub,
            ]);
            $mapped = match ($status) {
                'active' => 'active',
                'suspended' => 'past_due',
                'cancelled' => 'cancelled',
                'expired' => 'expired',
                default => $workspace->status,
            };
            $fields = ['status' => $mapped];
            if ($status === 'active') {
                $fields += ['plan' => $meta['plan'], 'cycle' => $meta['cycle'], 'seats' => (int) ($sub['quantity'] ?? 1), 'nonprofit' => $meta['nonprofit']];
            }
            // A cancelled subscription keeps access until the end of the paid period.
            if ($status === 'cancelled' && $next && Carbon::parse($next)->isFuture()) {
                $fields['status'] = 'active';
            }
            $workspace->update($fields);
        });
        AuditLog::record('Subscription ' . $status, "{$code} × " . ($sub['quantity'] ?? 1), $workspace->id);

        return $workspace->fresh();
    }

    public function recordPayment(array $resource): void
    {
        $subId = $resource['billing_agreement_id'] ?? null;
        $sub = $subId ? Subscription::where('provider_id', $subId)->first() : null;
        if (! $sub) {
            return;
        }
        $payment = Payment::firstOrNew(['provider_txn_id' => $resource['id']]);
        if (! $payment->exists) {
            $payment->invoice_number = 'TS-' . now()->format('Y') . '-' . str_pad((string) (Payment::whereYear('created_at', now()->year)->count() + 1), 5, '0', STR_PAD_LEFT);
        }
        $payment->fill([
            'workspace_id' => $sub->workspace_id, 'subscription_id' => $sub->id,
            'amount' => $resource['amount']['total'] ?? $resource['amount']['value'] ?? 0,
            'currency' => $resource['amount']['currency'] ?? $resource['amount']['currency_code'] ?? 'EUR',
            'status' => strtolower($resource['state'] ?? $resource['status'] ?? 'completed'),
            'paid_at' => isset($resource['create_time']) ? Carbon::parse($resource['create_time']) : now(),
            'raw' => $resource,
        ])->save();
        $ws = Workspace::find($sub->workspace_id);
        if ($ws && $ws->status === 'past_due') {
            $ws->update(['status' => 'active']);
        }
    }
}

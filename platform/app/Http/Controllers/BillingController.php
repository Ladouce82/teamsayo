<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\Billing;
use App\Services\PayPal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class BillingController extends Controller
{
    public function __construct(private PayPal $paypal, private Billing $billing)
    {
    }

    private function guard(Request $request)
    {
        $m = $request->attributes->get('membership');
        abort_unless($m->canManageBilling(), 403);

        return $m;
    }

    public function show(Request $request, Workspace $workspace): View
    {
        $this->guard($request);

        return view('billing', [
            'workspace' => $workspace,
            'catalogue' => Billing::catalogue(),
            'planIds' => $this->billing->planIds(),
            'clientId' => config('services.paypal.client_id'),
            'configured' => $this->paypal->configured() && count($this->billing->planIds()) > 0,
            'subscription' => Subscription::where('workspace_id', $workspace->id)->whereIn('status', ['active', 'suspended', 'approval_pending'])->latest()->first(),
            'payments' => Payment::where('workspace_id', $workspace->id)->latest('paid_at')->limit(36)->get(),
            'seatsUsed' => $workspace->seatsUsed(),
            'tax' => config('teamsayo.tax_percent'),
            'mode' => config('services.paypal.mode'),
        ]);
    }

    /** Called by the PayPal button after the customer approves the subscription. We re-check with PayPal. */
    public function confirm(Request $request, Workspace $workspace): JsonResponse
    {
        $this->guard($request);
        $data = $request->validate(['subscription_id' => ['required', 'string', 'max:64']]);
        try {
            $sub = $this->paypal->subscription($data['subscription_id']);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => 'We could not confirm the subscription with PayPal yet. It will update automatically within a few minutes.'], 202);
        }
        if (($sub['custom_id'] ?? '') !== $workspace->uuid || ! in_array($sub['status'] ?? '', ['ACTIVE', 'APPROVED', 'APPROVAL_PENDING'], true)) {
            return response()->json(['ok' => false, 'message' => 'This subscription does not belong to this workspace.'], 422);
        }
        $this->billing->applySubscription($sub, $workspace);

        return response()->json(['ok' => true, 'redirect' => route('billing', $workspace)]);
    }

    public function seats(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->guard($request);
        $sub = Subscription::where('workspace_id', $workspace->id)->where('status', 'active')->latest()->firstOrFail();
        $data = $request->validate(['seats' => ['required', 'integer', 'min:' . max(1, $workspace->seatsUsed()), 'max:5000'], 'plan' => ['nullable', Rule::in(array_keys(config('teamsayo.plans')))]]);
        $code = Billing::planCode($data['plan'] ?? $sub->plan, $sub->cycle, (bool) $workspace->nonprofit);
        $planId = $this->billing->planIds()[$code] ?? null;
        abort_unless($planId, 422);
        $link = $this->paypal->revise($sub->provider_id, $planId, (int) $data['seats'], route('billing', $workspace), route('billing', $workspace));
        AuditLog::record('Requested subscription change', "{$code} × {$data['seats']}", $workspace->id);

        return $link ? redirect()->away($link) : back()->with('status', 'Subscription updated. Changes appear here once PayPal confirms them.');
    }

    public function cancel(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->guard($request);
        $request->validate(['password' => ['required', 'current_password']]);
        $sub = Subscription::where('workspace_id', $workspace->id)->where('status', 'active')->latest()->firstOrFail();
        $this->paypal->cancel($sub->provider_id, 'Cancelled by customer in TeamSayo');
        $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $endsAt = $sub->current_period_end;
        if (! $endsAt || $endsAt->isPast()) {
            $workspace->update(['status' => 'cancelled']);
        }
        AuditLog::record('Cancelled subscription', null, $workspace->id);

        return back()->with('status', $endsAt && $endsAt->isFuture() ? 'Subscription cancelled. You keep access until ' . $endsAt->format('j M Y') . '.' : 'Subscription cancelled.');
    }

    public function invoice(Request $request, Workspace $workspace, Payment $payment): View
    {
        $this->guard($request);
        abort_unless($payment->workspace_id === $workspace->id, 404);

        return view('invoice', ['workspace' => $workspace, 'payment' => $payment, 'tax' => (float) config('teamsayo.tax_percent'), 'legal' => config('teamsayo.legal')]);
    }
}

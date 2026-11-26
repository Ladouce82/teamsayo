@extends('layouts.base', ['title' => 'Plan & billing'])
@php
  $np = (bool) $workspace->nonprofit;
  $fmt = fn ($v) => number_format($v, 2, ',', '.') . ' €';
  $statusPill = ['trialing' => 'a', 'active' => 'g', 'past_due' => 'r', 'expired' => 'r', 'cancelled' => 'r'][$workspace->status] ?? '';
@endphp
@section('content')
<div class="wrap">
<div class="row"><h1 class="sp">Plan & billing</h1><span class="pill {{ $statusPill }}">{{ ucfirst(str_replace('_', ' ', $workspace->status)) }}</span></div>
@include('partials-errors')
@if($workspace->status === 'trialing')
<div class="alert warn">Free trial of the Business plan until <strong>{{ $workspace->trial_ends_at?->format('j M Y') }}</strong> ({{ max(0, (int) now()->diffInDays($workspace->trial_ends_at, false)) }} days left). Choose a plan to keep going — your data stays exactly as it is.</div>
@elseif($workspace->isReadOnly())
<div class="alert err">This workspace is read-only because {{ $workspace->status === 'expired' ? 'the trial ended' : 'there is no active subscription' }}. Nothing has been deleted. Choose a plan below to unlock it.</div>
@elseif($workspace->status === 'past_due')
<div class="alert err">PayPal could not take the last payment. Update your payment method in PayPal to avoid interruption.</div>
@endif

@if($subscription)
<div class="card"><h2>Current subscription</h2>
<div class="grid g3"><div><div class="small muted">Plan</div><strong>{{ config("teamsayo.plans.{$subscription->plan}.name") }} · {{ $subscription->cycle }}</strong>{{ $np ? ' · non-profit' : '' }}</div>
<div><div class="small muted">Seats</div><strong>{{ $subscription->quantity }}</strong> <span class="small muted">({{ $seatsUsed }} in use)</span></div>
<div><div class="small muted">{{ $subscription->status === 'cancelled' ? 'Access until' : 'Next renewal' }}</div><strong>{{ $subscription->current_period_end?->format('j M Y') ?? '—' }}</strong></div></div>
@if($subscription->status === 'active')
<form method="POST" action="{{ route('billing.seats', $workspace) }}" class="row" style="margin-top:16px">@csrf
<label for="seats" style="margin:0">Change seats</label><input id="seats" name="seats" type="number" min="{{ max(1, $seatsUsed) }}" value="{{ $subscription->quantity }}" style="width:110px">
<select name="plan" aria-label="Plan" style="width:auto">@foreach(config('teamsayo.plans') as $k => $p)<option value="{{ $k }}" @selected($subscription->plan === $k)>{{ $p['name'] }}</option>@endforeach</select>
<button class="btn" type="submit">Update via PayPal</button></form>
<p class="small muted">Seat changes are confirmed in PayPal and charged pro rata on your next invoice.</p>
<details style="margin-top:10px"><summary class="small">Cancel subscription</summary>
<form method="POST" action="{{ route('billing.cancel', $workspace) }}" class="row" style="margin-top:8px" data-confirm="Cancel the subscription? You keep access until the end of the paid period, then the workspace becomes read-only.">@csrf
<input name="password" type="password" placeholder="Your password" aria-label="Your password" required style="max-width:220px"><button class="btn d" type="submit">Cancel subscription</button></form></details>
@endif
</div>
@endif

@if(!$subscription || $subscription->status !== 'active')
<div class="card"><h2>Choose a plan</h2>
<p class="small muted">Prices per user, excluding {{ $tax }}% VAT. Annual billing saves {{ (int) round(config('teamsayo.monthly_premium') / (1 + config('teamsayo.monthly_premium')) * 100) }}%.{{ $np ? ' Non-profit discount applied.' : '' }} Paid securely through PayPal (PayPal balance or card). Cancel any time.</p>
<div class="row" role="radiogroup" aria-label="Billing cycle" style="margin:8px 0 14px">
<label class="row" style="margin:0;color:var(--ink)"><input type="radio" name="cycle" value="annual" checked> Annual</label>
<label class="row" style="margin:0;color:var(--ink)"><input type="radio" name="cycle" value="monthly"> Monthly</label>
<label style="margin:0 0 0 auto">Seats <input id="buy-seats" type="number" min="{{ max(1, $seatsUsed) }}" value="{{ max(1, $seatsUsed) }}" style="width:90px;display:inline-block"></label></div>
<div class="grid g2">
@foreach(config('teamsayo.plans') as $key => $plan)
<div class="card" style="margin:0">
<h2>{{ $plan['name'] }}</h2>
<div><span style="font-size:26px;font-weight:700" data-price="{{ $key }}"></span> <span class="small muted" data-per="{{ $key }}"></span></div>
<p class="small muted">{{ $key === 'team' ? 'Records, meetings, actions, goals, check-ins, kudos and reports for one or more teams.' : 'Everything in Team plus reviews, skills & certifications, mentoring, AI insights, automations, integrations and multi-company roll-ups.' }}</p>
<div class="small" data-total="{{ $key }}"></div>
@if($configured)<div id="pp-{{ $key }}" style="margin-top:12px;min-height:45px"></div>@endif
</div>
@endforeach
</div>
@if(!$configured)<div class="alert warn" style="margin-top:14px">Online payment is being set up. Contact <a href="mailto:{{ config('teamsayo.legal.email') }}">{{ config('teamsayo.legal.email') }}</a> to subscribe now.</div>@endif
<div id="pp-msg" class="small" role="status" style="margin-top:10px"></div>
</div>
@endif

<div class="card"><h2>Invoices</h2>
@forelse($payments as $p)
<div class="row" style="padding:8px 0;border-bottom:1px solid var(--line)"><div class="sp"><strong>{{ $p->invoice_number }}</strong> · {{ $p->paid_at?->format('j M Y') }}</div><span>{{ $fmt($p->amount) }}</span><span class="pill {{ $p->status === 'completed' ? 'g' : 'a' }}">{{ $p->status }}</span><a class="btn s" href="{{ route('billing.invoice', [$workspace, $p]) }}">View</a></div>
@empty<p class="muted small">No invoices yet.</p>@endforelse
</div>
@if($mode === 'sandbox')<p class="small muted">PayPal sandbox mode — no real money is charged.</p>@endif
</div>
@endsection

@if((!$subscription || $subscription->status !== 'active') && $configured)
@push('scripts')
<script nonce="{{ $cspNonce }}" src="https://www.paypal.com/sdk/js?client-id={{ urlencode($clientId) }}&vault=true&intent=subscription&currency={{ config('teamsayo.currency') }}" data-csp-nonce="{{ $cspNonce }}"></script>
<script nonce="{{ $cspNonce }}">
(function () {
  var catalogue = @json($catalogue), planIds = @json($planIds), np = {{ $np ? 'true' : 'false' }}, tax = {{ (float) $tax }};
  var confirmUrl = @json(route('billing.confirm', $workspace)), token = @json(csrf_token()), wsId = @json($workspace->uuid);
  var msg = document.getElementById('pp-msg'), seatsEl = document.getElementById('buy-seats');
  function cycle() { return document.querySelector('input[name=cycle]:checked').value; }
  function code(plan) { return plan + '_' + cycle() + (np ? '_np' : ''); }
  function eur(v) { return v.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }); }
  function render() {
    Object.keys(@json(config('teamsayo.plans'))).forEach(function (plan) {
      var c = catalogue[code(plan)], n = Math.max(1, parseInt(seatsEl.value, 10) || 1), annual = cycle() === 'annual';
      document.querySelector('[data-price=' + plan + ']').textContent = eur(annual ? c.price / 12 : c.price);
      document.querySelector('[data-per=' + plan + ']').textContent = 'per user / month' + (annual ? ', billed yearly' : '');
      var net = c.price * n;
      document.querySelector('[data-total=' + plan + ']').textContent = n + ' × ' + eur(c.price) + ' = ' + eur(net) + ' + VAT ' + eur(net * tax / 100) + (annual ? ' per year' : ' per month');
    });
  }
  var buttons = {};
  function mount() {
    if (!window.paypal) { msg.textContent = 'PayPal could not load. Check your connection or ad blocker and reload.'; return; }
    Object.keys(@json(config('teamsayo.plans'))).forEach(function (plan) {
      var el = document.getElementById('pp-' + plan); if (!el) return;
      if (buttons[plan]) { buttons[plan].close(); }
      el.innerHTML = '';
      var id = planIds[code(plan)]; if (!id) { el.textContent = 'Not available yet.'; return; }
      buttons[plan] = paypal.Buttons({
        style: { layout: 'vertical', label: 'subscribe', shape: 'rect' },
        createSubscription: function (d, actions) { return actions.subscription.create({ plan_id: id, quantity: String(Math.max(1, parseInt(seatsEl.value, 10) || 1)), custom_id: wsId, application_context: { shipping_preference: 'NO_SHIPPING', brand_name: 'TeamSayo' } }); },
        onApprove: function (d) {
          msg.textContent = 'Confirming your subscription…';
          return fetch(confirmUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }, body: JSON.stringify({ subscription_id: d.subscriptionID }) })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j.ok) { location.href = j.redirect; } else { msg.textContent = j.message || 'Please reload in a minute.'; } })
            .catch(function () { msg.textContent = 'Payment approved. Reload this page in a minute to see your subscription.'; });
        },
        onError: function () { msg.textContent = 'PayPal reported a problem. Nothing was charged. Please try again.'; }
      });
      buttons[plan].render(el);
    });
  }
  document.querySelectorAll('input[name=cycle]').forEach(function (r) { r.addEventListener('change', function () { render(); mount(); }); });
  seatsEl.addEventListener('input', render);
  render(); mount();
})();
</script>
@endpush
@endif

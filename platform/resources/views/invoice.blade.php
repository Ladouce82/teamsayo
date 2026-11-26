<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Invoice {{ $payment->invoice_number }}</title>
<style>body{font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#1c2433;max-width:760px;margin:30px auto;padding:0 24px;font-size:14px;line-height:1.5}h1{font-size:22px;margin:0}table{width:100%;border-collapse:collapse;margin:24px 0}td,th{padding:8px;border-bottom:1px solid #e4e7ec;text-align:left}td.r,th.r{text-align:right}.muted{color:#5d6676}.head{display:flex;justify-content:space-between;gap:20px}.btn{display:inline-block;padding:8px 14px;border:1px solid #cfd4dc;border-radius:8px;background:#fff;cursor:pointer;font:inherit}@media print{.noprint{display:none}}</style></head>
<body>
@php
  $gross = (float) $payment->amount; $net = round($gross / (1 + $tax / 100), 2); $vat = round($gross - $net, 2);
  $f = fn ($v) => number_format($v, 2, ',', '.') . ' ' . $payment->currency;
  $sub = $payment->subscription_id ? \App\Models\Subscription::find($payment->subscription_id) : null;
@endphp
<p class="noprint"><button class="btn" type="button" id="print">Print / save as PDF</button> <a href="{{ route('billing', $workspace) }}">Back to billing</a></p>
<div class="head"><div><h1>Invoice</h1><div class="muted">No. {{ $payment->invoice_number }}<br>Date {{ $payment->paid_at?->format('d.m.Y') }}</div></div>
<div style="text-align:right"><strong>{{ $legal['company'] }}</strong><br>{!! nl2br(e($legal['address'])) !!}<br>{{ $legal['email'] }}@if(!empty($legal['vat_id']))<br>VAT ID {{ $legal['vat_id'] }}@endif</div></div>
<p style="margin-top:24px"><strong>Billed to</strong><br>{{ $workspace->name }}<br>{{ $workspace->owner?->name }} · {{ $workspace->owner?->email }}</p>
<table><tr><th>Description</th><th class="r">Amount</th></tr>
<tr><td>TeamSayo {{ $sub ? config("teamsayo.plans.{$sub->plan}.name") . ' plan, ' . $sub->cycle . ', ' . $sub->quantity . ' seat(s)' : 'subscription' }}</td><td class="r">{{ $f($net) }}</td></tr>
<tr><td>VAT {{ rtrim(rtrim(number_format($tax, 2), '0'), '.') }}%</td><td class="r">{{ $f($vat) }}</td></tr>
<tr><th>Total paid</th><th class="r">{{ $f($gross) }}</th></tr></table>
<p class="muted">Paid via PayPal (transaction {{ $payment->provider_txn_id }}). Status: {{ $payment->status }}.</p>
<p class="muted small">{{ $legal['company'] }}@if(!empty($legal['register'])) · {{ $legal['register'] }}@endif @if(!empty($legal['director'])) · Managing director: {{ $legal['director'] }}@endif</p>
<script nonce="{{ $cspNonce }}">document.getElementById('print').addEventListener('click', function () { window.print(); });</script>
</body></html>

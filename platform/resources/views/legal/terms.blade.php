@extends('layouts.base', ['title' => 'Terms of service'])
@section('content')
<div class="wrap" style="max-width:820px"><div class="card">
<h1>Terms of service</h1><p class="small muted">Last updated 26 November 2026 · {{ $legal['company'] }}</p>
<h2>1. Scope</h2><p>These terms govern the use of TeamSayo by businesses, public bodies and non-profit organisations ("Customer"). TeamSayo is not offered to consumers.</p>
<h2>2. Trial</h2><p>New workspaces get a free {{ config('teamsayo.trial_days') }}-day trial of the Business plan for up to 25 users. No payment details are needed. When it ends without a subscription the workspace becomes read-only; data is kept for 90 days.</p>
<h2>3. Subscription and payment</h2><p>Plans are charged per user (seat) per month or year, in advance, through PayPal, plus statutory VAT. Seats can be added at any time. Subscriptions renew automatically until cancelled. Cancellation takes effect at the end of the paid period; no refunds for partial periods unless required by law. Prices may change with 6 weeks' notice before the next renewal.</p>
<h2>4. Customer obligations</h2><p>The Customer is responsible for the data it records, for having a lawful basis to process its staff's data (including works-council agreements where required), for keeping credentials secure and for its users' compliance with these terms.</p>
<h2>5. Availability and support</h2><p>We aim for 99.5% monthly availability excluding announced maintenance. Support by email on business days.</p>
<h2>6. Data and export</h2><p>The Customer keeps all rights to its data and can export it at any time from workspace settings.</p>
<h2>7. Liability</h2><p>Unlimited liability for intent, gross negligence and injury to life, body or health. For slight negligence only for breach of essential obligations, limited to foreseeable, typical damage and at most the fees paid in the 12 months before the claim.</p>
<h2 id="dpa">8. Data processing agreement (Art. 28 GDPR)</h2><p>For workspace data we act as processor on the Customer's documented instructions (these terms and the Customer's use of the product). We ensure confidentiality of staff, apply the technical and organisational measures described in the privacy policy, use only the sub-processors listed there (with notice of changes and a right to object), assist with data subject requests and breach notifications (without undue delay, at most 48 hours), delete or return data at the end of the contract, and allow audits on reasonable notice. Data is hosted in the EU.</p>
<h2>9. Final provisions</h2><p>German law applies, excluding the UN Convention on Contracts for the International Sale of Goods. Place of jurisdiction is our registered office.</p>
</div></div>
@endsection

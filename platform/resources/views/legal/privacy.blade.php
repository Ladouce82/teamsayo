@extends('layouts.base', ['title' => 'Privacy policy'])
@section('content')
<div class="wrap" style="max-width:820px"><div class="card">
<h1>Privacy policy</h1><p class="small muted">Last updated {{ \Illuminate\Support\Carbon::parse('2026-11-26')->format('j F Y') }}</p>
<h2>1. Who we are</h2>
<p>{{ $legal['company'] }}, {{ str_replace("\n", ', ', $legal['address']) }} ("TeamSayo", "we"). Contact: <a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a>.</p>
<h2>2. Two roles</h2>
<p><strong>Controller</strong> for account, billing and website data (your name, email, password hash, sign-in history, invoices). <strong>Processor</strong> for everything your organisation records in its workspace (people, attendance, meetings, goals, reviews, files). For workspace data your organisation is the controller and our <a href="{{ route('legal', 'terms') }}#dpa">data processing terms</a> apply (Art. 28 GDPR).</p>
<h2>3. What we process and why</h2>
<ul>
<li><strong>Account</strong> — name, email, password (hashed), optional two-factor secret (encrypted), language. Purpose: providing the service (Art. 6(1)(b) GDPR).</li>
<li><strong>Security logs</strong> — IP address, browser, sign-in times, audit trail of changes. Purpose: protecting accounts and data (Art. 6(1)(f)). Kept 24 months.</li>
<li><strong>Billing</strong> — plan, seats, PayPal subscription and transaction IDs, invoices. We never see your card or bank details. Purpose: contract and tax law (Art. 6(1)(b), (c)). Invoices kept 10 years (§ 147 AO).</li>
<li><strong>Email</strong> — confirmations, invitations, reminders your workspace configured.</li>
</ul>
<h2>4. Recipients (sub-processors)</h2>
<ul>
<li>Hosting provider of teamsayo.com (servers and database, EU) — all data.</li>
<li>PayPal (Europe) S.à r.l. et Cie, S.C.A., Luxembourg — payments.</li>
<li>Email delivery provider — email address and message content.</li>
<li>Optional: if a workspace user adds their own AI key, prompts go directly from their browser to that AI provider; TeamSayo servers never receive or store the key.</li>
</ul>
<h2>5. Cookies</h2>
<p>We use only strictly necessary cookies: a session cookie and a security (CSRF) cookie, plus an optional "keep me signed in" cookie. No tracking or advertising cookies, so no consent banner is needed.</p>
<h2>6. Retention</h2>
<p>Workspace data is kept while the subscription or trial is active and read-only for 90 days after it ends, then deleted unless renewed. A deleted workspace is recoverable for 30 days, then erased, including backups within 14 further days.</p>
<h2>7. Your rights</h2>
<p>Access, rectification, erasure, restriction, portability and objection (Art. 15–21 GDPR). You can download your account data and delete your account in <em>My account</em>. For workspace data, contact your organisation. You may complain to a supervisory authority.</p>
<h2>8. Security</h2>
<p>TLS everywhere, hashed passwords, optional two-factor authentication, encrypted secrets, role-based access, rate limiting, nightly backups and an audit trail.</p>
</div></div>
@endsection

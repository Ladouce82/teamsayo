<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $title ?? 'TeamSayo' }} · TeamSayo</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%230b8f8f'/%3E%3Cpath d='M9 16.5l4.5 4.5L23 11.5' stroke='white' stroke-width='3' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
<style>
:root{--navy:#0f1f3d;--teal:#0b8f8f;--teal2:#e6f6f6;--ink:#1c2433;--mute:#5d6676;--line:#e4e7ec;--bg:#f6f7fb;--red:#c4231a;--green:#067647;--amber:#a3420a}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,Helvetica,Arial,sans-serif;color:var(--ink);background:var(--bg);line-height:1.5;font-size:15px}
a{color:var(--teal)}:focus-visible{outline:3px solid #f5b70a;outline-offset:2px;border-radius:6px}
.top{background:var(--navy);color:#fff}.top .in{max-width:1100px;margin:0 auto;padding:12px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:10px;color:#fff;font-weight:700;font-size:18px;text-decoration:none}.logo{width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,#0fa3a3,#3ccfcf);display:grid;place-items:center}
.top a.l{color:#d5dcea;text-decoration:none;font-size:14px}.top a.l:hover{color:#fff;text-decoration:underline}.sp{flex:1}
.wrap{max-width:1100px;margin:0 auto;padding:28px 20px 60px}.narrow{max-width:460px;margin:6vh auto;padding:0 20px}
.card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:22px;margin-bottom:18px}
h1{font-size:24px;margin:0 0 6px}h2{font-size:18px;margin:0 0 12px}.muted{color:var(--mute)}.small{font-size:13px}
label{display:block;font-size:13px;color:var(--mute);margin:12px 0 4px}
input,select,textarea{width:100%;font:inherit;padding:9px 11px;border:1px solid #cfd4dc;border-radius:9px;background:#fff;color:var(--ink)}
input[type=checkbox],input[type=radio]{width:auto}
.btn{display:inline-block;font:inherit;font-weight:600;border:1px solid #cfd4dc;background:#fff;color:var(--ink);padding:9px 15px;border-radius:9px;cursor:pointer;text-decoration:none}
.btn.p{background:var(--teal);border-color:var(--teal);color:#fff}.btn.d{color:var(--red)}.btn.s{padding:5px 10px;font-size:13px}.btn.block{width:100%;margin-top:16px}
.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.alert{padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:14px}.ok{background:#ecfdf3;color:var(--green)}.err{background:#fef3f2;color:var(--red)}.warn{background:#fffaeb;color:var(--amber)}
table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px 8px;border-bottom:1px solid var(--line);vertical-align:top;font-size:14px}th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--mute)}
.pill{display:inline-block;padding:2px 9px;border-radius:99px;font-size:12px;font-weight:600;background:#f2f4f7}.pill.g{background:#ecfdf3;color:var(--green)}.pill.a{background:#fffaeb;color:var(--amber)}.pill.r{background:#fef3f2;color:var(--red)}
.grid{display:grid;gap:16px}.g2{grid-template-columns:1fr 1fr}.g3{grid-template-columns:repeat(3,1fr)}@media(max-width:800px){.g2,.g3{grid-template-columns:1fr}}
.foot{text-align:center;font-size:12px;color:var(--mute);padding:20px}.foot a{color:var(--mute)}
code{background:#f2f4f7;padding:2px 6px;border-radius:5px}
</style>
@stack('head')
</head>
<body>
<header class="top"><div class="in">
  <a class="brand" href="{{ auth()->check() ? route('home') : route('login') }}"><span class="logo" aria-hidden="true">✓</span>TeamSayo</a><span class="sp"></span>
  @auth
    @isset($workspace)<a class="l" href="{{ route('app', $workspace) }}">← Back to {{ $workspace->name }}</a>@endisset
    <a class="l" href="{{ route('account') }}">My account</a>
    @if(auth()->user()->is_staff)<a class="l" href="{{ route('admin') }}">Operator console</a>@endif
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn s" type="submit">Sign out</button></form>
  @endauth
</div></header>
<main id="main">
@if(session('status'))<div class="{{ $narrow ?? false ? 'narrow' : 'wrap' }}" style="padding-bottom:0;margin-bottom:0"><div class="alert ok" role="status">{{ session('status') }}</div></div>@endif
@yield('content')
</main>
<footer class="foot">© {{ date('Y') }} TeamSayo · <a href="{{ route('legal', 'imprint') }}">Imprint</a> · <a href="{{ route('legal', 'privacy') }}">Privacy</a> · <a href="{{ route('legal', 'terms') }}">Terms</a>@if(app()->environment('staging')) · <strong>STAGING</strong>@endif</footer>
<script nonce="{{ $cspNonce ?? '' }}">document.addEventListener('submit',function(e){var m=e.target.getAttribute&&e.target.getAttribute('data-confirm');if(m&&!confirm(m))e.preventDefault()},true)</script>
@stack('scripts')
</body>
</html>

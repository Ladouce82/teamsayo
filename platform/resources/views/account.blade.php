@extends('layouts.base', ['title' => 'My account'])
@section('content')
<div class="wrap" style="max-width:860px"><h1>My account</h1>
@include('partials-errors')
<div class="grid g2">
<div class="card"><h2>Profile</h2>
<form method="POST" action="{{ route('account.profile') }}">@csrf
<label for="name">Name</label><input id="name" name="name" value="{{ old('name', $user->name) }}" required>
<label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required>
<p class="small muted">Changing your email asks you to confirm the new address.</p>
<button class="btn p" type="submit">Save</button></form></div>
<div class="card"><h2>Password</h2>
<form method="POST" action="{{ route('account.password') }}">@csrf
<label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password">
<label for="password">New password</label><input id="password" name="password" type="password" required autocomplete="new-password">
<label for="password_confirmation">Repeat new password</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
<button class="btn p" type="submit" style="margin-top:12px">Change password</button></form></div>
</div>

<div class="card"><h2>Two-factor authentication</h2>
@if($user->hasTwoFactor())
<p><span class="pill g">On</span> You are asked for a code from your authenticator app when you sign in.</p>
@if($recovery)<div class="alert warn"><strong>Save these recovery codes now.</strong> Each works once if you lose your phone. They will not be shown again.<div style="font-family:monospace;margin-top:8px;columns:2">@foreach($recovery as $c)<div>{{ $c }}</div>@endforeach</div></div>@endif
<form method="POST" action="{{ route('account.2fa.disable') }}" class="row">@csrf @method('DELETE')<input name="password" type="password" placeholder="Password" aria-label="Password" required style="max-width:220px"><button class="btn d" type="submit">Turn off</button></form>
@elseif($qr)
<p>Scan this code with an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password…), then enter the 6-digit code.</p>
<div style="width:200px">{!! $qr !!}</div>
<p class="small muted">Can't scan? Enter this key: <code>{{ trim(chunk_split($user->two_factor_secret, 4, ' ')) }}</code></p>
<form method="POST" action="{{ route('account.2fa.confirm') }}" class="row">@csrf<input name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" aria-label="Code" required style="max-width:160px"><button class="btn p" type="submit">Confirm</button></form>
@else
<p class="muted">Add a second step at sign-in. Recommended for owners, admins and directors.</p>
<form method="POST" action="{{ route('account.2fa.enable') }}" class="row">@csrf<input name="password" type="password" placeholder="Password" aria-label="Password" required style="max-width:220px"><button class="btn p" type="submit">Set up</button></form>
@endif
</div>

<div class="card"><h2>Signed-in devices</h2>
<table><tr><th>Device</th><th>IP</th><th>Last active</th><th></th></tr>
@foreach($sessions as $s)
<tr><td class="small">{{ \Illuminate\Support\Str::limit($s->user_agent, 70) }}</td><td class="small">{{ $s->ip_address }}</td><td class="small">{{ \Illuminate\Support\Carbon::createFromTimestamp($s->last_activity)->diffForHumans() }}</td>
<td>@if($s->id === $currentSession)<span class="pill g">This device</span>@else<form method="POST" action="{{ route('account.session.destroy', $s->id) }}">@csrf @method('DELETE')<button class="btn s" type="submit">Sign out</button></form>@endif</td></tr>
@endforeach</table></div>

<div class="card"><h2>Your data</h2>
<p class="small muted">Download what we hold about your account. Your records inside a workspace belong to that organisation; its admins can export them.</p>
<a class="btn" href="{{ route('account.export') }}">Download my account data</a>
<details style="margin-top:16px"><summary class="small" style="color:var(--red)">Delete my account</summary>
<p class="small">Your sign-in is removed. Workspaces you own alone are deleted (recoverable for 30 days by contacting support). Records your organisation keeps about your work stay with them.</p>
<form method="POST" action="{{ route('account.destroy') }}" class="row" data-confirm="Permanently delete your account?">@csrf @method('DELETE')<input name="password" type="password" placeholder="Password" aria-label="Password" required style="max-width:220px"><button class="btn d" type="submit">Delete account</button></form></details></div>
</div>
@endsection

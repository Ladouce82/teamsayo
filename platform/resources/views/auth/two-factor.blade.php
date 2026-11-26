@extends('layouts.base', ['title' => 'Two-factor authentication', 'narrow' => true])
@section('content')
<div class="narrow"><div class="card">
<h1>Two-factor authentication</h1><p class="muted small">Enter the 6-digit code from your authenticator app.</p>
@include('partials-errors')
<form method="POST" action="{{ route('two-factor.challenge') }}">@csrf
<label for="code">Authentication code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus pattern="[0-9 ]*">
<button class="btn p block" type="submit">Verify</button></form>
<details style="margin-top:16px"><summary class="small">Lost your phone? Use a recovery code</summary>
<form method="POST" action="{{ route('two-factor.challenge') }}">@csrf<label for="recovery_code">Recovery code</label><input id="recovery_code" name="recovery_code" autocomplete="off"><button class="btn block" type="submit">Use recovery code</button></form></details>
</div></div>
@endsection

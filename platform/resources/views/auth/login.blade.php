@extends('layouts.base', ['title' => 'Sign in', 'narrow' => true])
@section('content')
<div class="narrow"><div class="card">
<h1>Sign in</h1><p class="muted small">Welcome back to TeamSayo.</p>
@include('partials-errors')
<form method="POST" action="{{ route('login') }}">@csrf
<label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
<label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password">
<label class="row" style="color:var(--ink)"><input type="checkbox" name="remember" value="1"> Keep me signed in on this device</label>
<button class="btn p block" type="submit">Sign in</button></form>
<p class="small" style="margin-top:14px"><a href="{{ route('password.request') }}">Forgot your password?</a></p>
</div><p class="small muted" style="text-align:center">New to TeamSayo? <a href="{{ route('register') }}">Create an account and start a free trial</a></p></div>
@endsection

@extends('layouts.base', ['title' => 'Create your account', 'narrow' => true])
@section('content')
<div class="narrow"><div class="card">
<h1>Start your free trial</h1><p class="muted small">{{ config('teamsayo.trial_days') }} days of the Business plan. No payment details needed.</p>
@include('partials-errors')
<form method="POST" action="{{ route('register') }}">@csrf
<label for="name">Your name</label><input id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
<label for="email">Work email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
<label for="password">Password <span class="small">(at least 10 characters, letters and numbers)</span></label><input id="password" name="password" type="password" required autocomplete="new-password">
<label for="password_confirmation">Repeat password</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
<label class="row" style="color:var(--ink);align-items:flex-start"><input type="checkbox" name="terms" value="1" required style="margin-top:4px"> <span>I agree to the <a href="{{ route('legal', 'terms') }}" target="_blank">terms</a> and have read the <a href="{{ route('legal', 'privacy') }}" target="_blank">privacy policy</a>.</span></label>
<button class="btn p block" type="submit">Create account</button></form>
</div><p class="small muted" style="text-align:center">Already have an account? <a href="{{ route('login') }}">Sign in</a></p></div>
@endsection

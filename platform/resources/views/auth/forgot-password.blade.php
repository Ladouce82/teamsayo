@extends('layouts.base', ['title' => 'Reset password', 'narrow' => true])
@section('content')
<div class="narrow"><div class="card">
<h1>Reset your password</h1><p class="muted small">We will email you a link to choose a new password.</p>
@include('partials-errors')
<form method="POST" action="{{ route('password.email') }}">@csrf
<label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
<button class="btn p block" type="submit">Send reset link</button></form>
<p class="small" style="margin-top:14px"><a href="{{ route('login') }}">Back to sign in</a></p>
</div></div>
@endsection

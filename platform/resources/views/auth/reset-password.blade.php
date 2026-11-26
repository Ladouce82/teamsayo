@extends('layouts.base', ['title' => 'Choose a new password', 'narrow' => true])
@section('content')
<div class="narrow"><div class="card">
<h1>Choose a new password</h1>
@include('partials-errors')
<form method="POST" action="{{ route('password.update') }}">@csrf
<input type="hidden" name="token" value="{{ $token }}">
<label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email', $email) }}" required>
<label for="password">New password</label><input id="password" name="password" type="password" required autocomplete="new-password">
<label for="password_confirmation">Repeat new password</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
<button class="btn p block" type="submit">Save new password</button></form>
</div></div>
@endsection

@extends('layouts.base', ['title' => 'Confirm your email', 'narrow' => true])
@section('content')
<div class="narrow"><div class="card">
<h1>Check your inbox</h1>
<p>We sent a confirmation link to <strong>{{ auth()->user()->email }}</strong>. Click it to continue.</p>
<p class="small muted">No email after a few minutes? Check your spam folder, or send it again.</p>
<form method="POST" action="{{ route('verification.send') }}">@csrf<button class="btn p" type="submit">Send the link again</button></form>
<p class="small" style="margin-top:14px"><a href="{{ route('account') }}">Wrong address? Change it in your account.</a></p>
</div></div>
@endsection

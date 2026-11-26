@extends('layouts.base', ['title' => 'Invitation', 'narrow' => true])
@section('content')
<div class="narrow"><div class="card">
@if($invalid)
<h1>This invitation is no longer valid</h1><p class="muted">It may have expired, been used already or been withdrawn. Ask the person who invited you to send a new one.</p>
@else
<h1>Join {{ $invitation->workspace->name }}</h1>
<p>{{ $invitation->inviter?->name ?? 'A colleague' }} invited <strong>{{ $invitation->email }}</strong> to TeamSayo as <strong>{{ $invitation->role }}</strong>.</p>
@include('partials-errors')
@auth
  @if(auth()->user()->hasVerifiedEmail())
  <form method="POST" action="{{ route('invitations.accept', $token) }}">@csrf<button class="btn p block" type="submit">Accept and open {{ $invitation->workspace->name }}</button></form>
  @else
  <p class="alert warn">Confirm your email address first, then come back to this link.</p>
  @endif
@else
  <a class="btn p block" style="text-align:center" href="{{ route('register') }}">Create my account</a>
  <a class="btn block" style="text-align:center" href="{{ route('login') }}">I already have an account</a>
  <p class="small muted">Use <strong>{{ $invitation->email }}</strong> so the invitation matches your account.</p>
@endauth
@endif
</div></div>
@endsection

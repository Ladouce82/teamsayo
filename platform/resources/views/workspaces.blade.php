@extends('layouts.base', ['title' => 'Your workspaces'])
@section('content')
<div class="wrap" style="max-width:720px"><h1>Your workspaces</h1>
@foreach($memberships as $m)
<div class="card row"><div class="sp"><strong>{{ $m->workspace->name }}</strong><div class="small muted">{{ ucfirst($m->role) }} · {{ ucfirst($m->workspace->status) }}</div></div><a class="btn p" href="{{ route('app', $m->workspace) }}">Open</a></div>
@endforeach
<a class="btn" href="{{ route('onboarding') }}">+ Create another workspace</a></div>
@endsection

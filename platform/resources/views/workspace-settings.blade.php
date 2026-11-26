@extends('layouts.base', ['title' => 'Workspace settings'])
@section('content')
<div class="wrap" style="max-width:760px"><h1>Workspace settings</h1>
@include('partials-errors')
<div class="card"><h2>Name</h2>
<form method="POST" action="{{ route('workspace.rename', $workspace) }}" class="row">@csrf<input name="name" value="{{ $workspace->name }}" required aria-label="Workspace name" style="max-width:360px"><button class="btn p" type="submit">Save</button></form>
<p class="small muted" style="margin-top:12px">Companies, teams and members: <a href="{{ route('members', $workspace) }}">Members & teams</a>@if($membership->canManageBilling()) · Plan and invoices: <a href="{{ route('billing', $workspace) }}">Billing</a>@endif</p></div>
<div class="card"><h2>Export</h2><p class="small muted">Download every record in this workspace as JSON (for backups or to move to another system).</p><a class="btn" href="{{ route('workspace.export', $workspace) }}">Download workspace data</a></div>
@if($membership->role === 'owner')
<div class="card"><h2 style="color:var(--red)">Delete workspace</h2>
<p class="small">All members lose access immediately. Any subscription should be cancelled first. Data is kept for 30 days in case of mistakes, then permanently erased.</p>
<form method="POST" action="{{ route('workspace.destroy', $workspace) }}">@csrf @method('DELETE')
<label for="confirm">Type <strong>{{ $workspace->name }}</strong> to confirm</label><input id="confirm" name="confirm" required autocomplete="off">
<label for="password">Your password</label><input id="password" name="password" type="password" required>
<button class="btn d" type="submit" style="margin-top:12px">Delete workspace</button></form></div>
@endif
</div>
@endsection

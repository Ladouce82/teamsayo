@extends('layouts.base', ['title' => 'Members & teams'])
@section('content')
<div class="wrap">
<div class="row"><h1 class="sp">Members & teams</h1><span class="pill {{ $seatsUsed >= $seatLimit ? 'r' : 'g' }}">{{ $seatsUsed }} of {{ $seatLimit }} seats used</span>@if($me->canManageBilling())<a class="btn s" href="{{ route('billing', $workspace) }}">Billing</a>@endif</div>
<p class="muted small">Members sign in with their own account. Roles decide what they can see and change. Sensitive records (attendance, capacity, leave) are only visible to the person, their manager and directors.</p>
@include('partials-errors')
<div class="grid g2">
<div class="card"><h2>Invite a member</h2>
<form method="POST" action="{{ route('members.invite', $workspace) }}">@csrf
<div class="grid g2"><div><label for="i_email">Email</label><input id="i_email" name="email" type="email" required></div><div><label for="i_name">Name</label><input id="i_name" name="name"></div></div>
<div class="grid g2"><div><label for="i_role">Role</label><select id="i_role" name="role">@foreach($roles as $r)<option value="{{ $r }}" @selected($r === 'employee')>{{ ucfirst($r) }}</option>@endforeach</select></div>
<div><label for="i_company">Company</label><select id="i_company" name="company_id">@if($me->seesAllCompanies())<option value="">All companies</option>@endif @foreach($companies as $c)@if($me->seesAllCompanies() || $c->id === $me->company_id)<option value="{{ $c->id }}">{{ $c->name }}</option>@endif @endforeach</select></div></div>
<label for="i_title">Job title</label><input id="i_title" name="title">
@if($people->isNotEmpty())<label for="i_person">Link to an existing person record (keeps their history)</label><select id="i_person" name="person_key"><option value="">— New person —</option>@foreach($people as $p)<option value="{{ $p->item_key }}">{{ $p->data['name'] ?? $p->item_key }}{{ !empty($p->data['email']) ? ' · ' . $p->data['email'] : '' }}</option>@endforeach</select>@endif
<button class="btn p block" type="submit">Send invitation</button></form>
<p class="small muted">Roles: <b>Admin</b> everything · <b>Director</b> their company, approvals, settings · <b>Manager</b> their company; sensitive data only for their reports · <b>Employee</b> their own records · <b>Auditor</b> read-only.</p></div>
<div class="card"><h2>Pending invitations</h2>
@forelse($invites as $inv)
<div class="row" style="padding:8px 0;border-bottom:1px solid var(--line)"><div class="sp"><strong>{{ $inv->email }}</strong><div class="small muted">{{ ucfirst($inv->role) }} · {{ $inv->expires_at->isPast() ? 'expired' : 'expires ' . $inv->expires_at->format('j M') }}</div></div>
<form method="POST" action="{{ route('members.resend', [$workspace, $inv]) }}">@csrf<button class="btn s" type="submit">Resend</button></form>
<form method="POST" action="{{ route('members.revoke', [$workspace, $inv]) }}">@csrf @method('DELETE')<button class="btn s d" type="submit">Revoke</button></form></div>
@empty<p class="muted small">None.</p>@endforelse</div>
</div>
<div class="card" style="overflow:auto"><h2>Members</h2>
<table><tr><th>Member</th><th>Role · company · reports to</th><th>Security</th><th></th></tr>
@foreach($members as $m)
<tr><td><strong>{{ $m->user->name }}</strong><div class="small muted">{{ $m->user->email }}</div><div class="small muted">{{ $m->teams->pluck('name')->implode(', ') }}</div></td>
<td>@if($m->role === 'owner' || (!$me->isAdmin() && $m->isAdmin()))<span class="pill">{{ ucfirst($m->role) }}</span> {{ $m->company?->name }}
@else<form method="POST" action="{{ route('members.update', [$workspace, $m]) }}" class="row">@csrf @method('PATCH')
<select name="role" aria-label="Role for {{ $m->user->name }}" style="width:auto">@foreach($roles as $r)<option value="{{ $r }}" @selected($m->role === $r)>{{ ucfirst($r) }}</option>@endforeach</select>
<select name="company_id" aria-label="Company" style="width:auto"><option value="">All companies</option>@foreach($companies as $c)<option value="{{ $c->id }}" @selected($m->company_id === $c->id)>{{ $c->name }}</option>@endforeach</select>
<select name="manager_id" aria-label="Reports to" style="width:auto"><option value="">No manager</option>@foreach($members as $o)@if($o->id !== $m->id)<option value="{{ $o->id }}" @selected($m->manager_id === $o->id)>{{ $o->user->name }}</option>@endif @endforeach</select>
<input name="title" value="{{ $m->title }}" placeholder="Title" aria-label="Title" style="width:140px"><button class="btn s" type="submit">Save</button></form>@endif</td>
<td class="small">{!! $m->user->two_factor_confirmed_at ? '<span class="pill g">2FA on</span>' : '<span class="pill a">2FA off</span>' !!}<div class="muted">Last sign-in: {{ $m->user->last_login_at?->diffForHumans() ?? 'never' }}</div></td>
<td>@if($m->role !== 'owner' && $m->id !== $me->id && ($me->isAdmin() || !$m->isAdmin()))<form method="POST" action="{{ route('members.remove', [$workspace, $m]) }}" data-confirm="Remove {{ $m->user->name }}? Their records stay; they lose access.">@csrf @method('DELETE')<button class="btn s d" type="submit">Remove</button></form>@endif</td></tr>
@endforeach</table></div>
<div class="card"><h2>Teams</h2>
@foreach($teams as $t)
<form method="POST" action="{{ route('teams.save', $workspace) }}" class="card" style="padding:14px">@csrf<input type="hidden" name="team_id" value="{{ $t->id }}">
<div class="row"><input name="name" value="{{ $t->name }}" aria-label="Team name" style="max-width:260px"><select name="company_id" aria-label="Company" style="max-width:200px"><option value="">—</option>@foreach($companies as $c)<option value="{{ $c->id }}" @selected($t->company_id === $c->id)>{{ $c->name }}</option>@endforeach</select>
<select name="lead" aria-label="Team lead" style="max-width:200px"><option value="">No lead</option>@foreach($members as $m)<option value="{{ $m->id }}" @selected($t->members->firstWhere('pivot.is_lead', 1)?->id === $m->id)>{{ $m->user->name }}</option>@endforeach</select></div>
<div class="row small" style="margin-top:8px">@foreach($members as $m)<label class="row" style="margin:0;color:var(--ink)"><input type="checkbox" name="members[]" value="{{ $m->id }}" @checked($t->members->contains('id', $m->id))> {{ $m->user->name }}</label>@endforeach</div>
<div class="row" style="margin-top:8px"><button class="btn s p" type="submit">Save team</button></div></form>
<form method="POST" action="{{ route('teams.delete', [$workspace, $t]) }}" style="margin:-10px 0 14px">@csrf @method('DELETE')<button class="btn s d" type="submit">Delete {{ $t->name }}</button></form>
@endforeach
<form method="POST" action="{{ route('teams.save', $workspace) }}" class="row">@csrf<input name="name" placeholder="New team name" required aria-label="New team name" style="max-width:300px"><button class="btn" type="submit">+ Add team</button></form></div>
</div>
@endsection

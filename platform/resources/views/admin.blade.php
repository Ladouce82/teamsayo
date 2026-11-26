@extends('layouts.base', ['title' => 'Operator console'])
@section('content')
<div class="wrap"><h1>Operator console</h1>
<div class="grid g3">
<div class="card"><div class="small muted">MRR (net)</div><div style="font-size:26px;font-weight:700">{{ number_format($mrr, 2, ',', '.') }} €</div></div>
<div class="card"><div class="small muted">Users</div><div style="font-size:26px;font-weight:700">{{ $users }}</div><div class="small muted">{{ $signups }} new in 30 days</div></div>
<div class="card"><div class="small muted">Workspaces</div><div style="font-size:26px;font-weight:700">{{ $workspaces->count() }}</div><div class="small muted">{{ $workspaces->where('status', 'active')->count() }} paying · {{ $workspaces->where('status', 'trialing')->count() }} on trial</div></div>
</div>
<div class="card" style="overflow:auto"><h2>Workspaces</h2>
<table><tr><th>Name</th><th>Owner</th><th>Status</th><th>Plan</th><th>Members</th><th>Created</th></tr>
@foreach($workspaces as $w)<tr><td>{{ $w->name }}</td><td class="small">{{ $w->owner?->email }}</td><td><span class="pill">{{ $w->status }}</span>@if($w->status === 'trialing') <span class="small muted">until {{ $w->trial_ends_at?->format('j M') }}</span>@endif</td><td class="small">{{ $w->plan }} {{ $w->cycle }} × {{ $w->seats }}</td><td>{{ $w->memberships_count ?? '—' }}</td><td class="small">{{ $w->created_at->format('j M Y') }}</td></tr>@endforeach
</table></div>
<div class="grid g2">
<div class="card"><h2>Recent payments</h2>@forelse($payments as $p)<div class="row small" style="padding:6px 0;border-bottom:1px solid var(--line)"><span class="sp">{{ $p->workspace?->name }} · {{ $p->invoice_number }}</span><span>{{ number_format($p->amount, 2, ',', '.') }} {{ $p->currency }}</span><span class="pill">{{ $p->status }}</span></div>@empty<p class="muted small">None yet.</p>@endforelse</div>
<div class="card"><h2>Webhook errors</h2>@forelse($webhookErrors as $e)<div class="small" style="padding:6px 0;border-bottom:1px solid var(--line)"><strong>{{ $e->type }}</strong> · {{ $e->created_at->diffForHumans() }}<div class="muted">{{ \Illuminate\Support\Str::limit($e->error, 160) }}</div></div>@empty<p class="muted small">None.</p>@endforelse</div>
</div></div>
@endsection

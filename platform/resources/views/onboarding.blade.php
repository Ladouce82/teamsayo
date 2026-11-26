@extends('layouts.base', ['title' => 'Set up your workspace', 'narrow' => true])
@section('content')
<div class="narrow" style="max-width:560px"><div class="card">
<h1>Set up your workspace</h1><p class="muted small">A workspace holds your companies or offices, teams and everything they record. You can change all of this later.</p>
@include('partials-errors')
<form method="POST" action="{{ route('onboarding') }}">@csrf
<label for="workspace">Workspace name</label><input id="workspace" name="workspace" value="{{ old('workspace') }}" required placeholder="e.g. Nordlicht Group">
<label for="companies">Companies or offices in this workspace (one per line)</label><textarea id="companies" name="companies" rows="4" placeholder="Nordlicht Kiel&#10;Nordlicht Hamburg">{{ old('companies') }}</textarea>
<label for="sector">Template pack</label><select id="sector" name="sector">@foreach($packs as $p)<option @selected(old('sector') === $p)>{{ $p }}</option>@endforeach</select>
<label class="row" style="color:var(--ink)"><input type="checkbox" name="nonprofit" value="1" @checked(old('nonprofit'))> We are a registered non-profit (25% discount on paid plans)</label>
<button class="btn p block" type="submit">Create workspace</button></form>
</div></div>
@endsection

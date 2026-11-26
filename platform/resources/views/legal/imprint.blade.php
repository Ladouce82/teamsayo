@extends('layouts.base', ['title' => 'Imprint'])
@section('content')
<div class="wrap" style="max-width:760px"><div class="card">
<h1>Imprint</h1><p class="small muted">Information pursuant to § 5 DDG (German Digital Services Act).</p>
<p><strong>{{ $legal['company'] }}</strong><br>{!! nl2br(e($legal['address'])) !!}</p>
<p>Represented by: {{ $legal['director'] }}<br>Email: <a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a><br>Phone: {{ $legal['phone'] }}</p>
<p>Register: {{ $legal['register'] }}<br>VAT identification number (§ 27a UStG): {{ $legal['vat_id'] }}</p>
<p>Responsible for content (§ 18 Abs. 2 MStV): {{ $legal['director'] }}, address as above.</p>
<h2>Online dispute resolution</h2>
<p class="small">We are neither obliged nor willing to take part in dispute resolution proceedings before a consumer arbitration board. TeamSayo is offered to businesses only.</p>
</div></div>
@endsection

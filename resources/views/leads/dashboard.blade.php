@extends('layouts.app')
@section('title','Painel de leads')
@section('content')
@php
$pct=fn($part,$whole)=>$whole ? round($part/$whole*100).'%' : '—';
$max=max(1,$perDay->max());
$tables=[['De onde veio','Campanha',$campaigns],['Por anúncio','Anúncio',$ads],['Cachorro ou gato','Espécie',$species],['Quem atendeu','Pessoa',$team]];
@endphp
<div class="page-heading"><div><h1>Painel de leads</h1><p>De onde vieram, quantos a equipe chamou e quantos viraram cliente.</p></div></div>
<nav class="workspace-nav lead-tabs" aria-label="Leads"><a href="{{ route('leads.index') }}">Lista</a><a class="active" aria-current="page" href="{{ route('leads.dashboard') }}">Painel</a></nav>
<div class="tab-links lead-period" aria-label="Período">@foreach([7,30,90] as $option)<a class="text-button {{ $days===$option ? 'active' : '' }}" href="{{ route('leads.dashboard',['dias'=>$option]) }}">Últimos {{ $option }} dias</a>@endforeach</div>
<div class="lead-stats">
<div class="lead-stat"><strong>{{ $totals['total'] }}</strong><span>Chegaram</span></div>
<a class="lead-stat lead-stat-new" href="{{ route('leads.index',['situacao'=>'new']) }}"><strong>{{ $totals['waiting'] }}</strong><span>Esperando contato</span></a>
<div class="lead-stat"><strong>{{ $pct($totals['called'],$totals['total']) }}</strong><span>Foram chamados ({{ $totals['called'] }})</span></div>
<div class="lead-stat lead-stat-converted"><strong>{{ $pct($totals['converted'],$totals['total']) }}</strong><span>Viraram cliente ({{ $totals['converted'] }})</span></div>
<div class="lead-stat"><strong>{{ $firstCall ?? '—' }}</strong><span>Até o 1º contato</span></div>
</div>
<section class="card"><div class="card-heading"><div><h2>Leads por dia</h2></div></div>
<div class="lead-days" role="img" aria-label="{{ $totals['total'] }} leads nos últimos {{ $days }} dias">@foreach($perDay as $day=>$count)<span style="--h:{{ round($count/$max*100) }}%" title="{{ \Illuminate\Support\Carbon::parse($day)->format('d/m') }}: {{ $count }} lead(s)"></span>@endforeach</div>
<div class="lead-days-axis"><span>{{ \Illuminate\Support\Carbon::parse($perDay->keys()->first())->format('d/m') }}</span><span>Hoje</span></div></section>
<div class="lead-breakdowns">
@foreach($tables as [$title,$column,$rows])@if($rows->isNotEmpty() || $loop->first)
<section class="card"><div class="card-heading"><div><h2>{{ $title }}</h2></div></div>
@if($rows->isEmpty())<div class="empty-state">@include('components.icon',['name'=>'phone'])<strong>Nenhum lead neste período.</strong><p>Quando alguém responder um anúncio, aparece aqui.</p></div>@else
<div class="table-scroll"><table class="lead-rank"><thead><tr><th>{{ $column }}</th><th>Leads</th><th>Chamados</th><th>Viraram cliente</th></tr></thead><tbody>
@foreach($rows as $label=>$row)<tr><td><strong>{{ $label }}</strong><span class="lead-bar"><i style="width:{{ round($row['total']/max(1,$rows->max('total'))*100) }}%"></i></span></td><td>{{ $row['total'] }}</td><td>{{ $row['called'] }}</td><td><strong>{{ $row['converted'] }}</strong> <small>{{ $pct($row['converted'],$row['total']) }}</small></td></tr>@endforeach
</tbody></table></div>@endif</section>
@endif @endforeach
</div>
@endsection

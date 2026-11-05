@extends('layouts.app')
@section('title','Painel de leads')
@section('content')
@php
$pct=fn($part,$whole)=>$whole ? round($part/$whole*100).'%' : '—';
$day=fn($date)=>\Illuminate\Support\Carbon::parse($date)->format('d/m');
$count=fn($n)=>$n.' '.($n===1 ? 'lead' : 'leads');
$peak=$perDay->max();
// Topo do eixo sempre par, para a marca do meio ser um número inteiro.
$top=max(2,$peak+$peak%2);
$labelEvery=$days<=7 ? 1 : (int) ceil($days/6);
$today=$perDay->last();
$change=$previous ? round(($totals['total']-$previous)/$previous*100) : null;
$funnel=[['Chegaram',$totals['total'],'step-1'],['Foram chamados',$totals['called'],'step-2'],['Viraram cliente',$totals['converted'],'step-3']];
$tables=[['Pago ou orgânico','Tráfego',$traffic,''],['De onde veio','Origem',$origins,''],['Por campanha','Campanha',$campaigns,'lead-span-2'],['Por anúncio','Anúncio',$ads,''],['Cachorro ou gato','Espécie',$species,''],['Quem atendeu','Pessoa',$team,'']];
@endphp
<div class="page-heading lead-heading"><div><h1>Painel de leads</h1><p>De onde vieram, quantos a equipe chamou e quantos viraram cliente.</p></div><div class="lead-heading-side">@include('leads.live-status')<a class="text-link" href="{{ route('leads.index') }}">Ver lista</a></div></div>
<div class="lead-topline">
<nav class="lead-filters" aria-label="Período">@foreach([7,30,90] as $option)@php $isOn=$endsToday && $days===$option; @endphp<a class="{{ $isOn ? 'active' : '' }}" @if($isOn) aria-current="true" @endif href="{{ route('leads.dashboard',['dias'=>$option]+$chosen) }}">{{ $option }} dias</a>@endforeach</nav>
</div>
<form class="card analytics-filters lead-panel-filters" method="GET" action="{{ route('leads.dashboard') }}">
<label class="field">De<input type="date" name="de" value="{{ $start->format('Y-m-d') }}" max="{{ now('America/Sao_Paulo')->format('Y-m-d') }}"></label>
<label class="field">Até<input type="date" name="ate" value="{{ $end->format('Y-m-d') }}" max="{{ now('America/Sao_Paulo')->format('Y-m-d') }}"></label>
@foreach($filterOptions as $name=>[$label,$values])<label class="field">{{ $label }}<select name="{{ $name }}"><option value="">Todos</option>@foreach($values as $value)<option value="{{ $value }}" @selected(($chosen[$name] ?? null)===$value)>{{ $value }}</option>@endforeach</select></label>@endforeach
<div class="analytics-filter-actions"><button class="button button-primary">Aplicar filtros</button><a class="text-link" href="{{ route('leads.dashboard') }}">Limpar</a></div>
</form>
<div data-live>
<div class="lead-topline">
@include('leads.intake-status')
</div>
<div class="lead-stats">
<div class="lead-stat"><strong>{{ $totals['total'] }}</strong><span>Chegaram</span><small>@if($endsToday){{ $today }} hoje @endif @if($change!==null)· {{ $change>0 ? '+' : '' }}{{ $change }}% sobre os {{ $days }} dias anteriores @endif</small></div>
<a class="lead-stat lead-stat-new {{ $waitingTotal ? 'has-items' : '' }}" href="{{ route('leads.index',['situacao'=>'new']) }}"><strong>{{ $waitingTotal }}</strong><span>Esperando contato</span><small>Agora, em qualquer período</small></a>
<div class="lead-stat"><strong>{{ $pct($totals['called'],$totals['total']) }}</strong><span>Foram chamados</span><small>{{ $totals['called'] }} de {{ $totals['total'] }}</small></div>
<div class="lead-stat lead-stat-converted"><strong>{{ $pct($totals['converted'],$totals['total']) }}</strong><span>Viraram cliente</span><small>{{ $totals['converted'] }} de {{ $totals['total'] }}</small></div>
<div class="lead-stat"><strong>{{ $firstCall ?? '—' }}</strong><span>Até o 1º contato</span><small>Metade foi chamada antes disso</small></div>
</div>
<div class="lead-panel">
<section class="card lead-span-2"><div class="card-heading"><div><h2>Leads por dia</h2>@if($peak)<p>Pico de {{ $count($peak) }} em {{ $day($perDay->search($peak)) }} · média de {{ number_format($totals['total']/$days,1,',','') }} por dia</p>@endif</div></div>
<div class="lead-chart"><div class="lead-chart-grid" aria-hidden="true">@foreach([$top,$top/2,0] as $tick)<span data-tick="{{ $tick }}"></span>@endforeach</div>
<ol class="lead-chart-bars" aria-label="Leads por dia">@foreach($perDay as $date=>$value)@php $fromEnd=$perDay->count()-1-$loop->index; $isToday=$loop->last && $endsToday; @endphp<li class="{{ $isToday ? 'is-today' : '' }}" data-tip="{{ $isToday ? 'Hoje' : $day($date) }} · {{ $count($value) }}"><i style="height:{{ $value ? max(2,round($value/$top*100)) : 0 }}%"></i><span class="sr-only">{{ $day($date) }}: {{ $count($value) }}</span>@if($fromEnd%$labelEvery===0)<b aria-hidden="true">{{ $isToday ? 'Hoje' : $day($date) }}</b>@endif</li>@endforeach</ol></div></section>
<section class="card"><div class="card-heading"><div><h2>Do anúncio ao cliente</h2></div></div>
<div class="lead-funnel">@foreach($funnel as [$label,$value,$step])<div><div class="lead-funnel-row"><span>{{ $label }}</span><span><strong>{{ $value }}</strong> <small>{{ $pct($value,$totals['total']) }}</small></span></div><span class="lead-bar lead-bar-tall"><i class="{{ $step }}" style="width:{{ $totals['total'] ? round($value/$totals['total']*100) : 0 }}%"></i></span></div>@endforeach</div>
<p class="lead-note">Dos chamados, {{ $totals['lost'] }} sem interesse.</p></section>
<section class="card"><div class="card-heading"><div><h2>Esperando há mais tempo</h2></div>@if($waitingTotal)<a class="text-link" href="{{ route('leads.index',['situacao'=>'new']) }}">Ver {{ $waitingTotal }}</a>@endif</div>
@if($waiting->isEmpty())<div class="empty-state"><strong>Ninguém esperando.</strong><p>Todos os leads já foram chamados.</p></div>@else
<ul class="lead-queue">@foreach($waiting as $lead)<li data-lead-id="{{ $lead->id }}"><div><strong title="{{ $lead->name }}">{{ $lead->name }}</strong><small title="{{ $lead->campaignLabel() }}">{{ $lead->created_at->locale('pt_BR')->diffForHumans() }} · {{ $lead->campaignLabel() }}</small></div><div class="lead-queue-actions">@if($lead->whatsappUrl())<a class="button button-primary button-small lead-whats" href="{{ $lead->whatsappUrl($lead->greeting()) }}" target="_blank" rel="noopener" aria-label="WhatsApp de {{ $lead->name }}">@include('components.icon',['name'=>'phone'])</a>@endif @if(auth()->user()->canUse('leads.update'))<form method="POST" action="{{ route('leads.update',$lead) }}">@csrf<input type="hidden" name="status" value="contacted"><button class="button button-secondary button-small">Já chamei</button></form>@endif</div></li>@endforeach</ul>@endif</section>
@foreach($tables as [$title,$column,$rows,$span])@if($rows->isNotEmpty() || $loop->first)
<section class="card {{ $span }}"><div class="card-heading"><div><h2>{{ $title }}</h2></div></div>
@if($rows->isEmpty())<div class="empty-state"><strong>{{ $chosen ? 'Nenhum lead com esses filtros.' : 'Nenhum lead neste período.' }}</strong><p>Quando alguém responder um anúncio, aparece aqui.</p></div>@else
<div class="table-scroll"><table class="lead-rank" data-no-stack><thead><tr><th>{{ $column }}</th><th>Leads</th><th>Chamados</th><th>Clientes</th></tr></thead><tbody>
@foreach($rows as $label=>$row)<tr><td><strong>{{ $label }}</strong></td><td>{{ $row['total'] }}</td><td>{{ $row['called'] }}</td><td class="lead-rank-won"><strong>{{ $row['converted'] }}</strong> <small>{{ $pct($row['converted'],$row['total']) }}</small></td></tr>@endforeach
</tbody></table></div>@endif</section>
@endif @endforeach
@if($converted->isNotEmpty())<section class="card lead-span-2"><div class="card-heading"><div><h2>Quem converteu</h2><p>Leads finalizados no período e quem marcou.</p></div></div>
<div class="table-scroll"><table class="lead-rank lead-converted" data-no-stack><thead><tr><th>Cliente</th><th>Origem</th><th>Atendeu</th><th>Em</th></tr></thead><tbody>
@foreach($converted as $lead)<tr><td><strong>{{ $lead->name }}</strong></td><td>{{ $lead->originGroup() }}<small>{{ $lead->traffic() }}</small></td><td>{{ $lead->handler?->name ?? '—' }}</td><td>{{ $lead->updated_at->timezone('America/Sao_Paulo')->format('d/m') }}</td></tr>@endforeach
</tbody></table></div></section>@endif
</div>
</div>
@endsection

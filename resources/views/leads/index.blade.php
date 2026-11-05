@extends('layouts.app')
@section('title','Leads')
@section('content')
@php
$canUpdate=auth()->user()->canUse('leads.update');
$canDelete=in_array(auth()->user()->role,['central_admin','manager']) && auth()->user()->canUse('leads.destroy');
$filters=\App\Models\Lead::STATUSES;
$keep=fn(array $extra=[])=>array_filter($extra+$applied);
$link=fn(array $changes)=>route('leads.index',$keep($changes+['situacao'=>$status]));
$chosenDays=(int)($applied['dias'] ?? 0);
$saoPaulo=fn($date,$format='d/m H:i')=>$date->timezone('America/Sao_Paulo')->format($format);
@endphp
<div class="lead-head lead-black">
<div class="lead-head-top"><h1>Leads</h1><div class="lead-head-side">@include('leads.live-status')<a class="text-link" href="{{ route('leads.chat.index') }}">Chat</a><a class="text-link" href="{{ route('leads.dashboard') }}">Ver painel</a></div></div>
<div class="lead-head-tools">
<form class="filter-bar" method="GET" action="{{ route('leads.index') }}" data-live-search>@foreach(\Illuminate\Support\Arr::except($applied,'q') as $name=>$value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach<div class="search-input">@include('components.icon',['name'=>'search'])<input type="search" name="q" value="{{ $q }}" maxlength="80" aria-label="Buscar lead" placeholder="Nome, telefone ou e-mail"></div></form>
<details class="lead-filter-menu">
<summary>@include('components.icon',['name'=>'filter'])Filtros @if($filtersOn)<b>{{ $filtersOn }}</b>@endif</summary>
<div class="lead-filter-panel">
<h3>Período</h3>
@foreach([7,30,90] as $option)<a class="{{ $chosenDays===$option ? 'active' : '' }}" @if($chosenDays===$option) aria-current="true" @endif href="{{ $link(['dias'=>$chosenDays===$option ? null : $option,'de'=>null,'ate'=>null]) }}">Últimos {{ $option }} dias</a>@endforeach
<form class="lead-filter-dates" method="GET" action="{{ route('leads.index') }}">@foreach(array_filter(\Illuminate\Support\Arr::except($applied,['dias','de','ate'])+['situacao'=>$status]) as $name=>$value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach<label>De<input type="date" name="de" value="{{ $start?->format('Y-m-d') }}" max="{{ now('America/Sao_Paulo')->format('Y-m-d') }}"></label><label>Até<input type="date" name="ate" value="{{ $end?->format('Y-m-d') }}" max="{{ now('America/Sao_Paulo')->format('Y-m-d') }}"></label><button class="button button-secondary button-small">Aplicar datas</button></form>
@foreach(['conversao'=>'Conversão','trafego'=>'Tráfego','entrada'=>'Por onde entrou','origem'=>'De onde veio','responsavel'=>'Atendente'] as $key=>$title)
@continue(!isset($filterOptions[$key]))
<h3>{{ $title }}</h3>
@foreach($filterOptions[$key] as $name=>$total)
@php
$logo=$key==='origem' && in_array($name,['Instagram','Facebook','Google'],true) ? mb_strtolower($name) : null;
$isOn=($applied[$key] ?? null)===$name;
@endphp
<a class="{{ $isOn ? 'active' : '' }}" @if($isOn) aria-current="true" @endif href="{{ $link([$key=>$isOn ? null : $name]) }}">@if($logo)<img src="{{ asset('assets/brand/'.$logo.'.svg') }}" alt="" width="16" height="16">@endif{{ $name }}<b>{{ $total }}</b></a>
@endforeach
@endforeach
</div>
</details>
@if($filtersOn)<a class="text-link lead-filter-clear" href="{{ route('leads.index',array_filter(['q'=>$q,'situacao'=>$status])) }}">limpar filtros</a>@endif
</div>
</div>
<div data-live class="lead-black">
<div class="lead-topline">
<nav class="lead-filters" aria-label="Filtrar por situação">
<a class="{{ !$status ? 'active' : '' }}" @if(!$status) aria-current="true" @endif href="{{ route('leads.index',$keep()) }}">Todos <b>{{ $counts->sum() }}</b></a>
@foreach($filters as $value=>$label)<a class="{{ $status===$value ? 'active' : '' }}" @if($status===$value) aria-current="true" @endif href="{{ route('leads.index',$keep(['situacao'=>$value])) }}"><span><i class="lead-dot is-{{ $value }}"></i>{{ $label }}</span> <b>{{ $counts[$value] ?? 0 }}</b></a>@endforeach
</nav>
@include('leads.intake-status',['problemsOnly'=>true])
</div>
<section class="card lead-list"><div class="table-scroll"><table class="lead-table" data-no-stack><thead><tr><th>Nº</th><th>Chegou</th><th>Contato</th><th>Nome</th><th class="lead-phone">Telefone</th><th>Anúncio</th>@if($seesAll)<th>Atendente</th>@endif<th>Situação</th><th class="lead-origin-head">Origem</th><th aria-label="Ver dados"></th></tr></thead><tbody>
@forelse($leads as $lead)
@php
$extra=$lead->extra ?? [];
// O nome do anúncio diz mais que o número da campanha.
$tag=$extra['anuncio'] ?? $lead->campaignLabel();
$origin=$lead->origin();
$hours=$lead->created_at->diffInHours(now());
$statusLabel=\App\Models\Lead::STATUSES[$lead->status] ?? $lead->status;
$contact=$lead->phoneLabel() ?: $lead->email;
@endphp
<tr data-lead-id="{{ $lead->id }}">
<td class="lead-num">{{ $lead->id }}</td>
<td class="lead-nowrap" title="Chegou {{ $saoPaulo($lead->created_at,'d/m/Y H:i') }}">{{ $saoPaulo($lead->created_at) }}</td>
<td class="lead-nowrap">@if($lead->status==='new')<span class="{{ $hours>=24 ? 'is-late' : ($hours>=1 ? 'is-soon' : '') }}" title="Esperando {{ $lead->created_at->locale('pt_BR')->diffForHumans() }}">Esperando {{ $hours<1 ? '<1 h' : ($hours<24 ? floor($hours).' h' : floor($hours/24).' d') }}</span>@elseif($lead->contacted_at)<span title="{{ $lead->handler ? 'Por '.$lead->handler->name.' · ' : '' }}{{ $saoPaulo($lead->contacted_at,'d/m/Y H:i') }}">{{ $saoPaulo($lead->contacted_at) }}</span>@endif</td>
{{-- Em telas menores, a coluna de telefone some e ele aparece debaixo do nome. --}}
<td class="lead-name"><strong>{{ $lead->name }}</strong>@if($lead->source==='teste')<small>Lead de teste</small>@endif @if($contact)<small class="lead-name-phone">{{ $contact }}</small>@endif</td>
<td class="lead-phone" title="{{ $contact }}">{{ $contact }}</td>
<td class="lead-tags"><span class="lead-tag" title="{{ $tag }}"><span>{{ $tag }}</span></span></td>
@if($seesAll)<td class="lead-owner">{{ $lead->handler?->name ?? 'Na fila' }}</td>@endif
<td class="lead-step"><div>
@if($canUpdate)<form method="POST" action="{{ route('leads.update',$lead) }}">@csrf<select class="lead-status" name="status" aria-label="Situação de {{ $lead->name }}" data-lead-status>@foreach($filters as $value=>$label)<option value="{{ $value }}" @selected($lead->status===$value)>{{ $label }}</option>@endforeach</select><noscript><button class="button button-secondary button-small">Salvar</button></noscript></form>@else{{ $statusLabel }}@endif
</div></td>
<td class="lead-origin-cell">@if($origin)<span class="lead-origin" title="Veio de: {{ $origin['label'] }}">@forelse($origin['logos'] as $logo)<span class="lead-logo"><img src="{{ asset('assets/brand/'.$logo.'.svg') }}" alt="{{ ucfirst($logo) }}" width="17" height="17"></span>@empty<span class="lead-logo" role="img" aria-label="{{ $origin['label'] }}">{{ mb_strtoupper(mb_substr($origin['label'],0,1)) }}</span>@endforelse @if($lead->source!=='teste')<small class="lead-traffic">{{ $lead->entry()==='Site' ? 'Site · ' : '' }}{{ $lead->traffic() }}</small>@endif</span>@endif</td>
<td class="lead-corner"><div>
<i class="lead-dot is-{{ $lead->status }}" role="img" aria-label="{{ $statusLabel }}" title="{{ $statusLabel }}"></i>
<button type="button" class="lead-toggle" data-lead-toggle aria-expanded="false" aria-controls="lead-more-{{ $lead->id }}" aria-label="Ver dados de {{ $lead->name }}">@include('components.icon',['name'=>'down'])</button>
</div></td>
</tr>
<tr class="lead-more" id="lead-more-{{ $lead->id }}" data-lead-more="{{ $lead->id }}" hidden><td colspan="{{ $seesAll ? 10 : 9 }}">@include('leads.details')
@if($lead->whatsappUrl() || $lead->email || $canAssign || ($canDelete && $lead->source==='teste'))<div class="lead-actions">
@if($lead->whatsappUrl())<a class="text-button" href="{{ $lead->whatsappUrl($lead->greeting()) }}" target="_blank" rel="noopener">Abrir WhatsApp</a>
@elseif($lead->email)<a class="text-button" href="mailto:{{ $lead->email }}">Enviar e-mail</a>@endif
@if($lead->phone && ($lead->handled_by===null || $lead->handled_by===auth()->id()))<a class="text-button" href="{{ route('leads.chat.show',$lead) }}">Abrir chat</a>@endif
@if($canAssign)<form class="lead-assign" method="POST" action="{{ route('leads.assign',$lead) }}">@csrf<select class="lead-status" name="handled_by" aria-label="Novo atendente do lead de {{ $lead->name }}"><option value="">Na fila</option>@foreach($assignees[$lead->originating_franchise_id] ?? [] as $person)<option value="{{ $person->id }}" @selected($lead->handled_by===$person->id)>{{ $person->name }}</option>@endforeach @if($lead->handler && !collect($assignees[$lead->originating_franchise_id] ?? [])->contains('id',$lead->handled_by))<option value="" selected disabled>{{ $lead->handler->name }}</option>@endif</select><button class="text-button" data-confirm="Trocar o atendente deste lead?">Trocar atendente</button></form>@endif
@if($canDelete && $lead->source==='teste')<form method="POST" action="{{ route('leads.destroy',$lead) }}">@csrf<button class="text-button text-danger" data-confirm="Apagar este lead de teste?">Apagar teste</button></form>@endif
</div>@endif</td></tr>
@empty<tr><td colspan="{{ $seesAll ? 10 : 9 }}"><div class="empty-state"><strong>{{ ($q || $status || $filtersOn) ? 'Nenhum lead com esse filtro.' : 'Nenhum lead por aqui.' }}</strong><p>{{ ($q || $status || $filtersOn) ? 'Limpe a busca ou os filtros, ou escolha outra situação.' : 'Quando alguém responder um anúncio, aparece nesta lista.' }}</p></div></td></tr>@endforelse
</tbody></table></div><div class="pagination-wrapper">{{ $leads->links() }}</div></section>
</div>
@endsection

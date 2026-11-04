@extends('layouts.app')
@section('title','Leads')
@section('content')
@php
$canUpdate=auth()->user()->canUse('leads.update');
$canDelete=in_array(auth()->user()->role,['central_admin','manager']) && auth()->user()->canUse('leads.destroy');
$filters=['new'=>'Para chamar','contacted'=>'Em contato','converted'=>'Viraram cliente','lost'=>'Sem interesse'];
// Só o próximo passo de cada situação: a pessoa não precisa escolher entre muitos botões.
$actions=['new'=>['contacted'=>'Já chamei','lost'=>'Sem interesse'],'contacted'=>['converted'=>'Virou cliente','lost'=>'Sem interesse'],'converted'=>['contacted'=>'Reabrir'],'lost'=>['contacted'=>'Reabrir']];
$keep=fn(array $extra=[])=>array_filter($extra+['q'=>$q]);
$saoPaulo=fn($date,$format='d/m H:i')=>$date->timezone('America/Sao_Paulo')->format($format);
@endphp
<div class="lead-head lead-black">
<div class="lead-head-top"><h1>Leads</h1><div class="lead-head-side">@include('leads.live-status')<a class="text-link" href="{{ route('leads.dashboard') }}">Ver painel</a></div></div>
<form class="filter-bar" method="GET" action="{{ route('leads.index') }}" data-live-search><div class="search-input">@include('components.icon',['name'=>'search'])<input type="search" name="q" value="{{ $q }}" maxlength="80" aria-label="Buscar lead" placeholder="Pesquise por nome, telefone ou e-mail"></div></form>
</div>
<div data-live class="lead-black">
<div class="lead-topline">
<nav class="lead-filters" aria-label="Filtrar por situação">
<a class="{{ !$status ? 'active' : '' }}" @if(!$status) aria-current="true" @endif href="{{ route('leads.index',$keep()) }}">Todos <b>{{ $counts->sum() }}</b></a>
@foreach($filters as $value=>$label)<a class="{{ $status===$value ? 'active' : '' }}" @if($status===$value) aria-current="true" @endif href="{{ route('leads.index',$keep(['situacao'=>$value])) }}"><span><i class="lead-dot is-{{ $value }}"></i>{{ $label }}</span> <b>{{ $counts[$value] ?? 0 }}</b></a>@endforeach
</nav>
@include('leads.intake-status')
</div>
<section class="card lead-list"><div class="table-scroll"><table class="lead-table" data-no-stack><thead><tr><th>Nº</th><th>Chegou</th><th>Contato</th><th>Nome</th><th class="lead-phone">Telefone</th><th>Anúncio</th><th aria-label="Próximo passo"></th><th class="lead-origin-head">Origem</th><th aria-label="Situação"></th></tr></thead><tbody>
@forelse($leads as $lead)
@php
$extra=$lead->extra ?? [];
// O nome do anúncio diz mais que o número da campanha.
$tag=$extra['anuncio'] ?? $lead->campaignLabel();
$origin=$lead->origin();
$hours=$lead->created_at->diffInHours(now());
$active=in_array($lead->status,['new','contacted'],true);
$next=$canUpdate ? ($actions[$lead->status] ?? []) : [];
$step=$active ? array_slice($next,0,1,true) : [];
$rest=array_diff_key($next,$step);
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
<td class="lead-step"><div>
@foreach($step as $value=>$label)<form method="POST" action="{{ route('leads.update',$lead) }}">@csrf<input type="hidden" name="status" value="{{ $value }}"><button class="button button-secondary button-small">{{ $label }}</button></form>@endforeach
</div></td>
<td class="lead-origin-cell">@if($origin)<span class="lead-origin" title="Veio de: {{ $origin['label'] }}">@forelse($origin['logos'] as $logo)<span class="lead-logo"><img src="{{ asset('assets/brand/'.$logo.'.svg') }}" alt="{{ ucfirst($logo) }}" width="17" height="17"></span>@empty<span class="lead-logo" role="img" aria-label="{{ $origin['label'] }}">{{ mb_strtoupper(mb_substr($origin['label'],0,1)) }}</span>@endforelse</span>@endif</td>
<td class="lead-corner"><div>
<i class="lead-dot is-{{ $lead->status }}" role="img" aria-label="{{ $statusLabel }}" title="{{ $statusLabel }}"></i>
<button type="button" class="lead-toggle" data-lead-toggle aria-expanded="false" aria-controls="lead-more-{{ $lead->id }}" aria-label="Ver dados de {{ $lead->name }}">@include('components.icon',['name'=>'down'])</button>
</div></td>
</tr>
<tr class="lead-more" id="lead-more-{{ $lead->id }}" data-lead-more="{{ $lead->id }}" hidden><td colspan="9">@include('leads.details')
@if($lead->whatsappUrl() || $lead->email || $rest || ($canDelete && $lead->source==='teste'))<div class="lead-actions">
@if($lead->whatsappUrl())<a class="text-button" href="{{ $lead->whatsappUrl($lead->greeting()) }}" target="_blank" rel="noopener">Abrir WhatsApp</a>
@elseif($lead->email)<a class="text-button" href="mailto:{{ $lead->email }}">Enviar e-mail</a>@endif
@foreach($rest as $value=>$label)<form method="POST" action="{{ route('leads.update',$lead) }}">@csrf<input type="hidden" name="status" value="{{ $value }}"><button class="text-button">{{ $label }}</button></form>@endforeach
@if($canDelete && $lead->source==='teste')<form method="POST" action="{{ route('leads.destroy',$lead) }}">@csrf<button class="text-button text-danger" data-confirm="Apagar este lead de teste?">Apagar teste</button></form>@endif
</div>@endif</td></tr>
@empty<tr><td colspan="9"><div class="empty-state"><strong>{{ ($q || $status) ? 'Nenhum lead com esse filtro.' : 'Nenhum lead por aqui.' }}</strong><p>{{ ($q || $status) ? 'Limpe a busca ou escolha outra situação.' : 'Quando alguém responder um anúncio, aparece nesta lista.' }}</p></div></td></tr>@endforelse
</tbody></table></div><div class="pagination-wrapper">{{ $leads->links() }}</div></section>
</div>
@endsection

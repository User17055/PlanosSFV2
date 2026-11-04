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
<div class="page-heading lead-heading">
<div><h1>Leads</h1><p>{!! $canUpdate ? 'Chame no WhatsApp, clique em <b>Já chamei</b> e depois marque o resultado.' : 'Quem pediu contato pelos anúncios.' !!}</p></div>
<div class="lead-heading-side">@include('leads.live-status')<a class="text-link" href="{{ route('leads.dashboard') }}">Ver painel</a>
<form class="filter-bar" method="GET" action="{{ route('leads.index') }}" data-live-search>@if($status)<input type="hidden" name="situacao" value="{{ $status }}">@endif<div class="search-input">@include('components.icon',['name'=>'search'])<input type="search" name="q" value="{{ $q }}" maxlength="80" aria-label="Buscar lead" placeholder="Nome, telefone ou e-mail..."></div></form></div>
</div>
<div data-live>
<div class="lead-topline">
<nav class="lead-filters" aria-label="Filtrar por situação">
<a class="{{ !$status ? 'active' : '' }}" @if(!$status) aria-current="true" @endif href="{{ route('leads.index',$keep()) }}">Todos <b>{{ $counts->sum() }}</b></a>
{{-- O ponto de cada filtro também explica a cor do ponto em cada linha. --}}
@foreach($filters as $value=>$label)<a class="{{ $status===$value ? 'active' : '' }}" @if($status===$value) aria-current="true" @endif href="{{ route('leads.index',$keep(['situacao'=>$value])) }}"><i class="lead-dot is-{{ $value }}"></i>{{ $label }} <b>{{ $counts[$value] ?? 0 }}</b></a>@endforeach
</nav>
@include('leads.intake-status')
</div>
<section class="card lead-list"><div class="table-scroll"><table class="lead-table" data-no-stack><thead><tr><th>Nº</th><th>Chegou</th><th>Contato</th><th>Nome</th><th>Telefone</th><th>Anúncio</th><th aria-label="Próximo passo"></th><th aria-label="Origem, contato e situação"></th></tr></thead><tbody>
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
@endphp
<tr data-lead-id="{{ $lead->id }}">
<td class="lead-num">{{ $lead->id }}</td>
<td class="lead-nowrap" title="Chegou {{ $saoPaulo($lead->created_at,'d/m/Y H:i') }}">{{ $saoPaulo($lead->created_at) }}</td>
<td class="lead-nowrap">@if($lead->status==='new')<span class="{{ $hours>=24 ? 'is-late' : ($hours>=1 ? 'is-soon' : '') }}" title="Esperando {{ $lead->created_at->locale('pt_BR')->diffForHumans() }}">Esperando {{ $hours<1 ? '<1 h' : ($hours<24 ? floor($hours).' h' : floor($hours/24).' d') }}</span>@elseif($lead->contacted_at)<span title="{{ $lead->handler ? 'Por '.$lead->handler->name.' · ' : '' }}{{ $saoPaulo($lead->contacted_at,'d/m/Y H:i') }}">{{ $saoPaulo($lead->contacted_at) }}</span>@endif</td>
<td class="lead-name"><strong>{{ $lead->name }}</strong>@if($lead->source==='teste')<small>Lead de teste</small>@endif</td>
<td class="lead-phone" title="{{ $lead->phone ? '' : $lead->email }}">{{ $lead->phoneLabel() ?: $lead->email }}</td>
<td class="lead-tags"><span class="lead-tag" title="{{ $tag }}"><span>{{ $tag }}</span></span></td>
<td class="lead-step">@foreach($step as $value=>$label)<form method="POST" action="{{ route('leads.update',$lead) }}">@csrf<input type="hidden" name="status" value="{{ $value }}"><button class="button button-secondary button-small">{{ $label }}</button></form>@endforeach</td>
<td class="lead-corner"><div>
@if($origin)<span class="lead-origin" title="Veio de: {{ $origin['label'] }}">@forelse($origin['logos'] as $logo)<img src="{{ asset('assets/brand/'.$logo.'.svg') }}" alt="{{ ucfirst($logo) }}" width="18" height="18">@empty<span class="lead-initial" role="img" aria-label="{{ $origin['label'] }}">{{ mb_strtoupper(mb_substr($origin['label'],0,1)) }}</span>@endforelse</span>@endif
@if($lead->whatsappUrl())<a class="lead-icon" href="{{ $lead->whatsappUrl($lead->greeting()) }}" target="_blank" rel="noopener" title="Chamar no WhatsApp" aria-label="Chamar {{ $lead->name }} no WhatsApp"><img src="{{ asset('assets/brand/whatsapp.png') }}" alt="" width="22" height="22"></a>
@elseif($lead->email && $active)<a class="lead-icon" href="mailto:{{ $lead->email }}" title="Enviar e-mail" aria-label="Enviar e-mail para {{ $lead->name }}">@include('components.icon',['name'=>'mail'])</a>@endif
<i class="lead-dot is-{{ $lead->status }}" role="img" aria-label="{{ $statusLabel }}" title="{{ $statusLabel }}"></i>
<button type="button" class="lead-toggle" data-lead-toggle aria-expanded="false" aria-controls="lead-more-{{ $lead->id }}" aria-label="Ver dados de {{ $lead->name }}">@include('components.icon',['name'=>'down'])</button>
</div></td>
</tr>
<tr class="lead-more" id="lead-more-{{ $lead->id }}" data-lead-more="{{ $lead->id }}" hidden><td colspan="8">@include('leads.details')
@if($rest || ($canDelete && $lead->source==='teste'))<div class="lead-actions">
@foreach($rest as $value=>$label)<form method="POST" action="{{ route('leads.update',$lead) }}">@csrf<input type="hidden" name="status" value="{{ $value }}"><button class="text-button">{{ $label }}</button></form>@endforeach
@if($canDelete && $lead->source==='teste')<form method="POST" action="{{ route('leads.destroy',$lead) }}">@csrf<button class="text-button text-danger" data-confirm="Apagar este lead de teste?">Apagar teste</button></form>@endif
</div>@endif</td></tr>
@empty<tr><td colspan="8"><div class="empty-state"><strong>{{ ($q || $status) ? 'Nenhum lead com esse filtro.' : 'Nenhum lead por aqui.' }}</strong><p>{{ ($q || $status) ? 'Limpe a busca ou escolha outra situação.' : 'Quando alguém responder um anúncio, aparece nesta lista.' }}</p></div></td></tr>@endforelse
</tbody></table></div><div class="pagination-wrapper">{{ $leads->links() }}</div></section>
</div>
@endsection

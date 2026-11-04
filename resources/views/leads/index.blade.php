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
@foreach($filters as $value=>$label)<a class="{{ $status===$value ? 'active' : '' }}" @if($status===$value) aria-current="true" @endif href="{{ route('leads.index',$keep(['situacao'=>$value])) }}">{{ $label }} <b>{{ $counts[$value] ?? 0 }}</b></a>@endforeach
</nav>
@include('leads.intake-status')
</div>
<section class="card lead-list"><div class="table-scroll"><table class="lead-table" data-no-stack><thead><tr><th>Quem</th><th>De onde veio</th><th>Situação</th><th>O que fazer</th><th aria-label="Ver dados"></th></tr></thead><tbody>
@forelse($leads as $lead)
@php
$extra=$lead->extra ?? [];
// O nome do anúncio diz mais que o número da campanha.
$from=collect([ctype_digit((string) $lead->campaign) ? null : $lead->campaign,$extra['anuncio'] ?? null])->filter()->implode(' · ') ?: $lead->campaignLabel();
$channel=$lead->channel();
$logo=collect(['instagram','facebook'])->first(fn($name)=>str_contains(mb_strtolower((string) $channel),$name));
$hours=$lead->created_at->diffInHours(now());
$active=in_array($lead->status,['new','contacted'],true);
@endphp
<tr data-lead-id="{{ $lead->id }}">
<td><strong>{{ $lead->name }}</strong>@if($lead->source==='teste')<small>Lead de teste</small>@endif @if($lead->phone)<small>{{ $lead->phoneLabel() }}</small>@endif @if($lead->email)<small><a href="mailto:{{ $lead->email }}">{{ $lead->email }}</a></small>@endif</td>
<td>@if($channel)<span class="lead-channel">@if($logo)<img src="{{ asset('assets/brand/'.$logo.'.svg') }}" alt="" width="15" height="15">@endif{{ $channel }}</span>@endif {{ $from }}</td>
<td>@if($lead->status==='new')<strong class="{{ $hours>=24 ? 'is-late' : ($hours>=1 ? 'is-soon' : '') }}">Esperando {{ $lead->created_at->locale('pt_BR')->diffForHumans() }}</strong><small>Chegou {{ $lead->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</small>@else<strong class="is-{{ $lead->status }}" title="Chegou {{ $lead->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}">{{ \App\Models\Lead::STATUSES[$lead->status] ?? $lead->status }}</strong>@if($lead->handler)<small>Por {{ $lead->handler->name }} · {{ $lead->updated_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</small>@endif @endif</td>
<td><div class="lead-actions">
@if($lead->whatsappUrl())<a class="{{ $lead->status==='new' ? 'button button-primary button-small lead-whats' : ($active ? 'button button-secondary button-small lead-whats lead-whats-quiet' : 'text-button') }}" href="{{ $lead->whatsappUrl($lead->greeting()) }}" target="_blank" rel="noopener">@if($active)@include('components.icon',['name'=>'phone'])@endif WhatsApp</a>
@elseif($lead->email && $active)<a class="button button-secondary button-small lead-whats lead-whats-quiet" href="mailto:{{ $lead->email }}">Enviar e-mail</a>@endif
@foreach($canUpdate ? ($actions[$lead->status] ?? []) : [] as $value=>$label)<form method="POST" action="{{ route('leads.update',$lead) }}">@csrf<input type="hidden" name="status" value="{{ $value }}"><button class="{{ $active && $loop->first ? 'button button-secondary button-small' : 'text-button' }}">{{ $label }}</button></form>@endforeach
@if($canDelete && $lead->source==='teste')<form method="POST" action="{{ route('leads.destroy',$lead) }}">@csrf<button class="text-button text-danger" data-confirm="Apagar este lead de teste?">Apagar teste</button></form>@endif
</div></td>
<td class="lead-toggle-cell"><button type="button" class="lead-toggle" data-lead-toggle aria-expanded="false" aria-controls="lead-more-{{ $lead->id }}" aria-label="Ver dados de {{ $lead->name }}">@include('components.icon',['name'=>'down'])</button></td>
</tr>
<tr class="lead-more" id="lead-more-{{ $lead->id }}" data-lead-more="{{ $lead->id }}" hidden><td colspan="5">@include('leads.details')</td></tr>
@empty<tr><td colspan="5"><div class="empty-state"><strong>{{ ($q || $status) ? 'Nenhum lead com esse filtro.' : 'Nenhum lead por aqui.' }}</strong><p>{{ ($q || $status) ? 'Limpe a busca ou escolha outra situação.' : 'Quando alguém responder um anúncio, aparece nesta lista.' }}</p></div></td></tr>@endforelse
</tbody></table></div><div class="pagination-wrapper">{{ $leads->links() }}</div></section>
</div>
@endsection

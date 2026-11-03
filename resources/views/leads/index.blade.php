@extends('layouts.app')
@section('title','Leads')
@section('content')
@php
$badge=['new'=>'badge-amber','contacted'=>'badge-blue','converted'=>'badge-green','lost'=>'badge-gray'];
$canDelete=in_array(auth()->user()->role,['central_admin','manager']);
$labels=['idade_do_pet'=>'Idade do pet','especie_do_pet'=>'Espécie do pet','formulario'=>'Formulário','anuncio'=>'Anúncio'];
// O primeiro de cada grupo é o próximo passo natural e vira o botão principal.
$actions=['new'=>['contacted'=>'Chamei','converted'=>'Virou cliente','lost'=>'Sem interesse'],'contacted'=>['converted'=>'Virou cliente','lost'=>'Sem interesse','new'=>'Voltar para novo'],'converted'=>['contacted'=>'Reabrir'],'lost'=>['contacted'=>'Reabrir']];
$keep=fn(array $extra=[])=>array_filter($extra+['q'=>$q]);
@endphp
<div class="page-heading"><div><h1>Leads</h1><p>Quem pediu contato pelos anúncios. Chame pelo WhatsApp e marque como foi.</p></div>@include('leads.live-status')</div>
<div class="lead-toolbar">
<nav class="workspace-nav lead-tabs" aria-label="Leads"><a class="active" aria-current="page" href="{{ route('leads.index') }}">Lista</a><a href="{{ route('leads.dashboard') }}">Painel</a></nav>
<form class="filter-bar" method="GET" action="{{ route('leads.index') }}" data-live-search>@if($status)<input type="hidden" name="situacao" value="{{ $status }}">@endif<div class="search-input">@include('components.icon',['name'=>'search'])<input type="search" name="q" value="{{ $q }}" maxlength="80" aria-label="Buscar lead" placeholder="Nome, telefone ou e-mail..."></div><button class="button button-secondary">Buscar</button></form>
</div>
<div data-live>
@include('leads.intake-status')
<div class="lead-stats" role="group" aria-label="Filtrar por situação">
<a class="lead-stat {{ !$status ? 'active' : '' }}" @if(!$status) aria-current="true" @endif href="{{ route('leads.index',$keep()) }}"><strong>{{ $counts->sum() }}</strong><span>Todos</span></a>
@foreach(\App\Models\Lead::STATUSES as $value=>$label)<a class="lead-stat lead-stat-{{ $value }} {{ $status===$value ? 'active' : '' }} {{ ($counts[$value] ?? 0) ? 'has-items' : '' }}" @if($status===$value) aria-current="true" @endif href="{{ route('leads.index',$keep(['situacao'=>$value])) }}"><strong>{{ $counts[$value] ?? 0 }}</strong><span>{{ $label }}</span></a>@endforeach
</div>
<section class="card"><div class="table-scroll"><table class="lead-table" data-no-stack><thead><tr><th>Lead</th><th>Contato</th><th>Origem</th><th>Chegou</th><th>Situação</th></tr></thead><tbody>
@forelse($leads as $lead)
@php
$arrived=$lead->created_at->timezone('America/Sao_Paulo');
$hours=$lead->status==='new' ? $lead->created_at->diffInHours(now()) : 0;
$extra=$lead->extra ?? [];
$origin=array_intersect_key($extra,array_flip(['anuncio','formulario']));
@endphp
<tr class="lead-row {{ $lead->status==='new' ? 'lead-new' : '' }}" data-lead-id="{{ $lead->id }}">
<td data-label="Lead"><div class="lead-person"><span class="lead-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($lead->name,0,1)) }}</span><div><strong>{{ $lead->name }}</strong>@if($lead->source==='teste') <span class="badge badge-gray">Teste</span>@endif
<div class="lead-facts">@if($lead->pet_name)<span>Pet: {{ $lead->pet_name }}</span>@endif @foreach(array_diff_key($extra,$origin) as $key=>$value)<span>{{ $labels[$key] ?? ucfirst(str_replace('_',' ',$key)) }}: {{ str_replace('_',' ',$value) }}</span>@endforeach</div></div></div></td>
<td data-label="Contato">@if($lead->whatsappUrl())<a class="button button-small lead-whats {{ in_array($lead->status,['new','contacted'],true) ? 'button-primary' : 'button-secondary lead-whats-quiet' }}" href="{{ $lead->whatsappUrl($lead->greeting()) }}" target="_blank" rel="noopener">@include('components.icon',['name'=>'phone']) WhatsApp</a><small>{{ $lead->phoneLabel() }}</small>@elseif($lead->phone)<strong>{{ $lead->phone }}</strong>@else<small>Sem telefone</small>@endif @if($lead->email)<small><a href="mailto:{{ $lead->email }}">{{ $lead->email }}</a></small>@endif</td>
<td data-label="Origem">@if($lead->campaign || !$origin)<strong class="lead-origin">{{ $lead->campaignLabel() }}</strong>@endif @foreach($origin as $key=>$value)<small>{{ $labels[$key] }}: {{ $value }}</small>@endforeach</td>
<td data-label="Chegou"><strong>{{ $arrived->locale('pt_BR')->diffForHumans() }}</strong><small>{{ $arrived->format('d/m/Y H:i') }}</small>@if($hours>=24)<span class="badge badge-red">Atrasado</span>@elseif($hours>=1)<span class="badge badge-amber">Chamar logo</span>@endif</td>
<td data-label="Situação" class="lead-state"><span class="badge {{ $badge[$lead->status] ?? 'badge-gray' }}">{{ \App\Models\Lead::STATUSES[$lead->status] ?? $lead->status }}</span>@if($lead->handler)<small>{{ $lead->handler->name }}@if($lead->contacted_at) · {{ $lead->contacted_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}@endif</small>@endif
<div class="lead-actions">@foreach($actions[$lead->status] ?? [] as $value=>$label)<form method="POST" action="{{ route('leads.update',$lead) }}">@csrf<input type="hidden" name="status" value="{{ $value }}"><button class="{{ $loop->first ? 'button button-secondary button-small' : 'text-button' }}">{{ $label }}</button></form>@endforeach @if($canDelete && $lead->source==='teste')<form method="POST" action="{{ route('leads.destroy',$lead) }}">@csrf<button class="text-button text-danger" data-confirm="Apagar este lead de teste?">Apagar teste</button></form>@endif</div></td>
</tr>
@empty<tr><td colspan="5"><div class="empty-state">@include('components.icon',['name'=>'phone'])<strong>{{ ($q || $status) ? 'Nenhum lead com esse filtro.' : 'Nenhum lead por aqui.' }}</strong><p>{{ ($q || $status) ? 'Limpe a busca ou escolha outra situação.' : 'Quando alguém responder um anúncio, aparece nesta lista.' }}</p></div></td></tr>@endforelse
</tbody></table></div><div class="pagination-wrapper">{{ $leads->links() }}</div></section>
</div>
@endsection

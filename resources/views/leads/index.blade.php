@extends('layouts.app')
@section('title','Leads')
@section('content')
@php
$badge=['new'=>'badge-amber','contacted'=>'badge-green','converted'=>'badge-green','lost'=>'badge-gray'];
$canDelete=in_array(auth()->user()->role,['central_admin','manager']);
$labels=['idade_do_pet'=>'Idade do pet','especie_do_pet'=>'Espécie do pet','formulario'=>'Formulário','anuncio'=>'Anúncio'];
$greeting=fn($lead)=>'Olá, '.explode(' ',trim($lead->name))[0].'! Aqui é da São Francisco. Vi que você pediu informações sobre os planos para o seu pet. Posso te ajudar?';
$keep=fn(array $extra=[])=>array_filter($extra+['q'=>$q]);
@endphp
<div class="page-heading"><div><h1>Leads</h1><p>Quem pediu contato pelos anúncios. Chame pelo WhatsApp e marque como foi.</p></div></div>
<div class="lead-stats" role="group" aria-label="Filtrar por situação">
<a class="lead-stat {{ !$status ? 'active' : '' }}" href="{{ route('leads.index',$keep()) }}"><strong>{{ $counts->sum() }}</strong><span>Todos</span></a>
@foreach(\App\Models\Lead::STATUSES as $value=>$label)<a class="lead-stat lead-stat-{{ $value }} {{ $status===$value ? 'active' : '' }}" href="{{ route('leads.index',$keep(['situacao'=>$value])) }}"><strong>{{ $counts[$value] ?? 0 }}</strong><span>{{ $label }}</span></a>@endforeach
</div>
<form class="filter-bar" method="GET" action="{{ route('leads.index') }}">@if($status)<input type="hidden" name="situacao" value="{{ $status }}">@endif<div class="search-input">@include('components.icon',['name'=>'search'])<input name="q" value="{{ $q }}" aria-label="Buscar lead" placeholder="Nome, telefone ou e-mail..."></div><button class="button button-secondary">Buscar</button>@if($q)<a class="text-button" href="{{ route('leads.index',array_filter(['situacao'=>$status])) }}">Limpar</a>@endif</form>
<section class="card"><div class="table-scroll"><table class="lead-table"><thead><tr><th>Lead</th><th>Contato</th><th>Chegou</th><th>Situação</th><th>Ações</th></tr></thead><tbody>
@forelse($leads as $lead)@php $arrived=$lead->created_at->timezone('America/Sao_Paulo'); @endphp<tr class="{{ $lead->status==='new' ? 'lead-new' : '' }}">
<td><strong>{{ $lead->name }}</strong>@if($lead->source==='teste') <span class="badge badge-gray">Teste</span>@endif @if($lead->pet_name)<small>Pet: {{ $lead->pet_name }}</small>@endif @foreach(array_slice($lead->extra ?? [],0,6,true) as $key=>$value)<small>{{ $labels[$key] ?? ucfirst(str_replace('_',' ',$key)) }}: {{ $value }}</small>@endforeach</td>
<td>@if($lead->whatsappUrl())<a class="button button-primary button-small lead-whats" href="{{ $lead->whatsappUrl($greeting($lead)) }}" target="_blank" rel="noopener">@include('components.icon',['name'=>'phone']) WhatsApp</a><small>{{ $lead->phoneLabel() }}</small>@elseif($lead->phone)<strong>{{ $lead->phone }}</strong>@else<small>Sem telefone</small>@endif @if($lead->email)<small>{{ $lead->email }}</small>@endif</td>
<td><strong title="{{ $arrived->format('d/m/Y H:i') }}">{{ $arrived->locale('pt_BR')->diffForHumans() }}</strong><small>{{ $lead->campaign ?? 'Anúncio' }}</small></td>
<td><span class="badge {{ $badge[$lead->status] ?? 'badge-gray' }}">{{ \App\Models\Lead::STATUSES[$lead->status] ?? $lead->status }}</span>@if($lead->handler)<small>{{ $lead->handler->name }}@if($lead->contacted_at) · {{ $lead->contacted_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}@endif</small>@endif</td>
<td class="lead-actions">@foreach(['contacted'=>'Chamei','converted'=>'Virou cliente','lost'=>'Sem interesse','new'=>'Voltar para novo'] as $value=>$label)@if($lead->status!==$value && !($value==='new' && $lead->status==='new'))<form method="POST" action="{{ route('leads.update',$lead) }}">@csrf<input type="hidden" name="status" value="{{ $value }}"><button class="text-button">{{ $label }}</button></form>@endif @endforeach @if($canDelete && $lead->source==='teste')<form method="POST" action="{{ route('leads.destroy',$lead) }}">@csrf<button class="text-button text-danger" data-confirm="Apagar este lead de teste?">Apagar teste</button></form>@endif</td>
</tr>
@empty<tr><td colspan="5"><div class="empty-state">@include('components.icon',['name'=>'phone'])<strong>{{ ($q || $status) ? 'Nenhum lead com esse filtro.' : 'Nenhum lead por aqui.' }}</strong><p>{{ ($q || $status) ? 'Limpe a busca ou escolha outra situação.' : 'Quando alguém responder um anúncio, aparece nesta lista.' }}</p></div></td></tr>@endforelse
</tbody></table></div><div class="pagination-wrapper">{{ $leads->links() }}</div></section>
@endsection

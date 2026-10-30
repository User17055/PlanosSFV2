@extends('layouts.app')
@section('title','Leads')
@section('content')
@php $badge=['new'=>'badge-amber','contacted'=>'badge-green','converted'=>'badge-green','lost'=>'badge-gray']; @endphp
<div class="page-heading"><div><h1>Leads</h1><p>Quem pediu contato pelos anúncios. Chame pelo WhatsApp e marque a situação.</p></div></div>
<section class="card"><div class="card-heading"><div class="tab-links">
<a class="text-button {{ !$status ? 'active' : '' }}" href="{{ route('leads.index') }}">Todos ({{ $counts->sum() }})</a>
@foreach(\App\Models\Lead::STATUSES as $value=>$label)<a class="text-button {{ $status===$value ? 'active' : '' }}" href="{{ route('leads.index',['situacao'=>$value]) }}">{{ $label }} ({{ $counts[$value] ?? 0 }})</a>@endforeach
</div></div>
<div class="table-scroll"><table><thead><tr><th>Recebido</th><th>Nome</th><th>Contato</th><th>Pet</th><th>Origem</th><th>Situação</th><th>Ações</th></tr></thead><tbody>
@forelse($leads as $lead)<tr>
<td>{{ $lead->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</td>
<td><strong>{{ $lead->name }}</strong></td>
<td>@if($lead->whatsappUrl())<a class="text-link" href="{{ $lead->whatsappUrl() }}" target="_blank" rel="noopener">@include('components.icon',['name'=>'phone']) {{ $lead->phone }}</a>@elseif($lead->phone){{ $lead->phone }}@endif @if($lead->email)<small>{{ $lead->email }}</small>@endif</td>
<td>{{ $lead->pet_name ?? '—' }}</td>
<td>{{ $lead->campaign ?? 'Anúncio' }}</td>
<td><span class="badge {{ $badge[$lead->status] ?? 'badge-gray' }}">{{ \App\Models\Lead::STATUSES[$lead->status] ?? $lead->status }}</span>@if($lead->handler)<small>{{ $lead->handler->name }}</small>@endif</td>
<td>@foreach(['contacted'=>'Chamei','converted'=>'Virou cliente','lost'=>'Sem interesse'] as $value=>$label)@if($lead->status!==$value)<form method="POST" action="{{ route('leads.update',$lead) }}" style="display:inline">@csrf<input type="hidden" name="status" value="{{ $value }}"><button class="text-button">{{ $label }}</button></form> @endif @endforeach</td>
</tr>
@empty<tr><td colspan="7"><div class="empty-state"><strong>Nenhum lead por aqui.</strong><p>Quando alguém responder um anúncio, aparece nesta lista.</p></div></td></tr>@endforelse
</tbody></table></div><div class="pagination-wrapper">{{ $leads->links() }}</div></section>
@endsection

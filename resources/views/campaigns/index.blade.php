@extends('layouts.app')
@section('title','Campanhas')
@section('content')
<div class="page-heading"><div><h1>Campanhas</h1><p>Mensalidades grátis depois da entrada. Com “Vale no site todo”, toda contratação feita pelo site no período entra sozinha; nas vendas da equipe, o vendedor escolhe na proposta.</p></div></div>

<section class="card form-card campaign-card">
<h2>Campanhas cadastradas</h2>
@foreach($campaigns as $campaign)<form method="POST" action="{{ route('campaigns.update',$campaign) }}" class="campaign-row">@csrf @include('campaigns._fields',['campaign'=>$campaign])<a class="text-link campaign-count" href="{{ route('campaigns.index',['campanha'=>$campaign->id]) }}">{{ $campaign->proposals_count }} {{ $campaign->proposals_count===1 ? 'contrato' : 'contratos' }}</a><button class="button button-secondary button-small">Salvar</button></form>@endforeach
<details class="campaign-new" @if($campaigns->isEmpty()) open @endif><summary>@include('components.icon',['name'=>'plus']) Nova campanha</summary><form method="POST" action="{{ route('campaigns.store') }}" class="campaign-row">@csrf @include('campaigns._fields',['campaign'=>null])<button class="button button-primary button-small">Criar campanha</button></form></details>
</section>

<section class="card section-gap">
<div class="card-heading"><div><h2>Quem está isento</h2><p>Contratos com mensalidade grátis e quando começa a cobrança mensal.@if($chosen) <a class="text-link" href="{{ route('campaigns.index') }}">Ver de todas as campanhas</a>@endif</p></div></div>
<div class="table-scroll"><table><thead><tr><th>Tutor e pet</th><th>Plano</th><th>Campanha</th><th>Veio pelo</th><th>Isenção</th><th>1ª mensalidade</th><th>Etapa</th><th></th></tr></thead><tbody>
@forelse($proposals as $proposal)@php $stage=$proposal->stage(); $first=$firstBill($proposal); $site=$proposal->creator?->role==='customer'; @endphp
<tr><td><strong>{{ $proposal->customer->name }}</strong><small>{{ $proposal->pet->name }}</small></td>
<td>{{ $proposal->productVersion->name }}</td>
<td>{{ $proposal->campaign?->name ?? 'Campanha' }}</td>
<td><strong>{{ $site ? 'Site' : 'Equipe' }}</strong>@unless($site)<small>{{ $proposal->creator?->name }}</small>@endunless</td>
<td>{{ $proposal->free_months }} {{ $proposal->free_months===1 ? 'mensalidade' : 'mensalidades' }}</td>
<td>{{ $first ? $first->format('d/m/Y') : 'Depois de assinar' }}</td>
<td><span class="badge {{ $stage==='ativo' ? 'badge-green' : ($stage==='encerrada' ? 'badge-gray' : 'badge-amber') }}"><span class="status-dot"></span>{{ \App\Models\Proposal::STAGES[$stage] }}</span></td>
<td><a class="table-arrow" href="{{ route('proposals.show',$proposal) }}" aria-label="Ver proposta de {{ $proposal->customer->name }}">@include('components.icon',['name'=>'chevron'])</a></td></tr>
@empty<tr><td colspan="8"><div class="empty-state"><strong>Ninguém isento ainda.</strong><p>Quando uma venda entrar numa campanha, ela aparece aqui.</p></div></td></tr>@endforelse
</tbody></table></div>
{{ $proposals->links() }}
</section>
@endsection

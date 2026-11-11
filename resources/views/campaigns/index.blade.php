@extends('layouts.app')
@section('title','Campanhas')
@section('content')
<div class="page-heading"><div><h1>Campanhas</h1><p>Mensalidades grátis depois da entrada. A campanha principal vale no site todo; nas vendas da equipe, o vendedor escolhe no início da contratação.</p></div></div>

<section class="card form-card campaign-card">
<h2>Campanhas ativas</h2>
<p class="dash-simple-note">A campanha <strong>principal</strong> vale no site todo: quem contrata pelo site no período ganha sozinho. Só uma pode ser a principal. Na venda da equipe, a principal já vem marcada e o vendedor pode trocar por outra ativa ou por “Sem campanha”.</p>
@php $today=now('America/Sao_Paulo')->toDateString(); $active=$campaigns->where('active',true); $archived=$campaigns->where('active',false); @endphp
@forelse($active as $campaign)@php $inPeriod=$campaign->starts_on->toDateString()<=$today && (!$campaign->ends_on || $campaign->ends_on->toDateString()>=$today); @endphp
<div class="campaign-item">
<div class="campaign-badges">@if($campaign->whole_site)<span class="campaign-state is-principal">Principal · site todo</span>@endif<span class="campaign-state {{ $inPeriod ? 'is-on' : '' }}">{{ $inPeriod ? 'Em vigor' : ($campaign->starts_on->toDateString()>$today ? 'Ainda não começou' : 'Período encerrado') }}</span><a class="text-link campaign-count" href="{{ route('campaigns.index',['campanha'=>$campaign->id]) }}">{{ $campaign->proposals_count }} {{ $campaign->proposals_count===1 ? 'contrato' : 'contratos' }}</a></div>
<form method="POST" action="{{ route('campaigns.update',$campaign) }}" class="campaign-row">@csrf @include('campaigns._fields',['campaign'=>$campaign])<button class="button button-secondary button-small">Salvar</button></form>
<div class="campaign-actions">
@if($campaign->whole_site)<form method="POST" action="{{ route('campaigns.not-principal',$campaign) }}">@csrf<button class="text-button" data-confirm="Tirar “{{ $campaign->name }}” do site todo? Quem contratar pelo site deixa de ganhar a campanha sozinho.">Deixar de ser principal</button></form>
@else<form method="POST" action="{{ route('campaigns.principal',$campaign) }}">@csrf<button class="text-button" data-confirm="Tornar “{{ $campaign->name }}” a campanha principal? Ela passa a valer no site todo{{ $active->firstWhere('whole_site',true) ? ', no lugar de “'.$active->firstWhere('whole_site',true)->name.'”' : '' }}.">Tornar principal</button></form>@endif
<form method="POST" action="{{ route('campaigns.archive',$campaign) }}">@csrf<button class="text-button text-danger" data-confirm="Arquivar “{{ $campaign->name }}”? Ela some das novas vendas. Quem já entrou nela continua com os meses grátis.">Arquivar</button></form>
</div>
</div>
@empty<div class="analytics-empty">Nenhuma campanha ativa.</div>@endforelse
<details class="campaign-new" @if($campaigns->isEmpty()) open @endif><summary>@include('components.icon',['name'=>'plus']) Nova campanha</summary><form method="POST" action="{{ route('campaigns.store') }}" class="campaign-row">@csrf @include('campaigns._fields',['campaign'=>null])<button class="button button-primary button-small">Criar campanha</button></form></details>
@if($archived->isNotEmpty())<details class="campaign-new"><summary>Campanhas arquivadas ({{ $archived->count() }})</summary>
@foreach($archived as $campaign)<div class="campaign-item is-archived"><div><strong>{{ $campaign->label() }}</strong><small>{{ $campaign->starts_on->format('d/m/Y') }}{{ $campaign->ends_on ? ' a '.$campaign->ends_on->format('d/m/Y') : '' }} · {{ $campaign->proposals_count }} {{ $campaign->proposals_count===1 ? 'contrato' : 'contratos' }}</small></div><form method="POST" action="{{ route('campaigns.restore',$campaign) }}">@csrf<button class="button button-secondary button-small" data-confirm="Reativar “{{ $campaign->name }}”? Ela volta a aparecer nas novas vendas da equipe.">Reativar</button></form></div>@endforeach
</details>@endif
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

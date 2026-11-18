@extends('layouts.app')
@section('title','Campanhas')
@section('content')
<div class="page-heading"><div><h1>Campanhas</h1><p>Mensalidades grátis e desconto nas seguintes, depois da entrada. A campanha principal vale no site todo; nas vendas da equipe, o vendedor escolhe no início da contratação.</p></div></div>

<section class="card form-card campaign-card">
<h2>Campanhas ativas</h2>
<p class="dash-simple-note">A campanha <strong>principal</strong> vale no site todo: quem contrata pelo site no período ganha sozinho. Só uma pode ser a principal. Na venda da equipe, a principal já vem marcada e o vendedor pode trocar por outra ativa ou por “Sem campanha”.</p>
@php
$today=now('America/Sao_Paulo')->toDateString();
$state=fn($c)=>$c->starts_on->toDateString()>$today ? 'Ainda não começou' : ($c->ends_on && $c->ends_on->toDateString()<$today ? 'Período encerrado' : 'Em vigor');
$period=fn($c)=>$c->ends_on ? $c->starts_on->format('d/m/Y').' a '.$c->ends_on->format('d/m/Y') : 'A partir de '.$c->starts_on->format('d/m/Y');
// Principal primeiro, depois as que valem hoje, as que vão começar e as encerradas.
$rank=['Em vigor'=>1,'Ainda não começou'=>2,'Período encerrado'=>3];
$active=$campaigns->where('active',true)->sortBy(fn($c)=>$c->whole_site ? 0 : $rank[$state($c)])->values();
$archived=$campaigns->where('active',false);
$onSite=$active->first(fn($c)=>$c->whole_site && $state($c)==='Em vigor');
@endphp
@if($onSite)<p class="campaign-now">No site agora: <strong>{{ $onSite->name }}</strong> · {{ $onSite->benefit() }}{{ $onSite->ends_on ? ', até '.$onSite->ends_on->format('d/m/Y') : '' }}.</p>
@else<p class="campaign-now is-off">Nenhuma campanha valendo no site agora: quem contrata pelo site paga o preço normal.</p>@endif
@forelse($active as $campaign)
<div class="campaign-item">
<div class="campaign-head">
<div><h3>{{ $campaign->name }}</h3><small>{{ $period($campaign) }}</small></div>
<div class="campaign-badges">@if($campaign->whole_site)<span class="campaign-state is-principal">Principal · site todo</span>@endif<span class="campaign-state {{ $state($campaign)==='Em vigor' ? 'is-on' : '' }}">{{ $state($campaign) }}</span><a class="text-link campaign-count" href="{{ route('campaigns.index',['campanha'=>$campaign->id]) }}#quem-esta">{{ $campaign->proposals_count }} {{ $campaign->proposals_count===1 ? 'contrato' : 'contratos' }}</a></div>
</div>
@include('campaigns._flow',['campaign'=>$campaign,'live'=>false])
<div class="campaign-actions">
<details class="campaign-edit"><summary class="text-button">@include('components.icon',['name'=>'edit']) Editar</summary><form method="POST" action="{{ route('campaigns.update',$campaign) }}" class="campaign-row">@csrf @include('campaigns._fields',['campaign'=>$campaign])<button class="button button-secondary button-small">Salvar</button></form></details>
@if($campaign->whole_site)<form method="POST" action="{{ route('campaigns.not-principal',$campaign) }}">@csrf<button class="text-button" data-confirm="Tirar “{{ $campaign->name }}” do site todo? Quem contratar pelo site deixa de ganhar a campanha sozinho.">Deixar de ser principal</button></form>
@else<form method="POST" action="{{ route('campaigns.principal',$campaign) }}">@csrf<button class="text-button" data-confirm="Tornar “{{ $campaign->name }}” a campanha principal? Ela passa a valer no site todo{{ $active->firstWhere('whole_site',true) ? ', no lugar de “'.$active->firstWhere('whole_site',true)->name.'”' : '' }}.">Tornar principal</button></form>@endif
<form method="POST" action="{{ route('campaigns.archive',$campaign) }}">@csrf<button class="text-button text-danger" data-confirm="Arquivar “{{ $campaign->name }}”? Ela some das novas vendas. Quem já entrou nela continua com o que ganhou.">Arquivar</button></form>
</div>
</div>
@empty<div class="analytics-empty">Nenhuma campanha ativa.</div>@endforelse
<details class="campaign-new" @if($campaigns->isEmpty()) open @endif><summary>@include('components.icon',['name'=>'plus']) Nova campanha</summary><form method="POST" action="{{ route('campaigns.store') }}" class="campaign-row">@csrf @include('campaigns._fields',['campaign'=>null])<button class="button button-primary button-small">Criar campanha</button></form></details>
@if($archived->isNotEmpty())<details class="campaign-new"><summary>Campanhas arquivadas ({{ $archived->count() }})</summary>
@foreach($archived as $campaign)<div class="campaign-item is-archived"><div><strong>{{ $campaign->label() }}</strong><small>{{ $campaign->starts_on->format('d/m/Y') }}{{ $campaign->ends_on ? ' a '.$campaign->ends_on->format('d/m/Y') : '' }} · {{ $campaign->proposals_count }} {{ $campaign->proposals_count===1 ? 'contrato' : 'contratos' }}</small></div><form method="POST" action="{{ route('campaigns.restore',$campaign) }}">@csrf<button class="button button-secondary button-small" data-confirm="Reativar “{{ $campaign->name }}”? Ela volta a aparecer nas novas vendas da equipe.">Reativar</button></form></div>@endforeach
</details>@endif
</section>

<section class="card section-gap" id="quem-esta">
<div class="card-heading"><div><h2>Quem está em campanha</h2><p>Contratos com mensalidade grátis ou desconto e quando começa a cobrança mensal.</p></div></div>
@php $withProposals=$campaigns->filter(fn($c)=>$c->all_proposals_count>0 || $c->id===$chosen); @endphp
@if($withProposals->count()>1 || $chosen)<nav class="tab-links campaign-filter" aria-label="Filtrar por campanha"><a class="text-button {{ $chosen ? '' : 'active' }}" href="{{ route('campaigns.index') }}#quem-esta" @unless($chosen) aria-current="page" @endunless>Todas</a>@foreach($withProposals as $campaign)<a class="text-button {{ $chosen===$campaign->id ? 'active' : '' }}" href="{{ route('campaigns.index',['campanha'=>$campaign->id]) }}#quem-esta" @if($chosen===$campaign->id) aria-current="page" @endif>{{ $campaign->name }} ({{ $campaign->all_proposals_count }})</a>@endforeach</nav>@endif
<div class="table-scroll"><table><thead><tr><th>Tutor e pet</th><th>Plano</th><th>Campanha</th><th>Veio pelo</th><th>1ª mensalidade</th><th>Etapa</th><th></th></tr></thead><tbody>
@forelse($proposals as $proposal)@php $stage=$proposal->stage(); $first=$firstBill($proposal); $site=$proposal->creator?->role==='customer'; @endphp
<tr><td><strong>{{ $proposal->customer->name }}</strong><small>{{ $proposal->pet->name }}</small></td>
<td>{{ $proposal->productVersion->name }}</td>
<td><strong>{{ $proposal->campaign?->name ?? 'Campanha' }}</strong><small>{{ $proposal->campaignBenefit() }}</small></td>
<td><strong>{{ $site ? 'Site' : 'Equipe' }}</strong>@unless($site)<small>{{ $proposal->creator?->name }}</small>@endunless</td>
<td class="nowrap">{{ $first ? $first->format('d/m/Y') : 'Depois de assinar' }}</td>
<td><span class="badge stage-badge stage-{{ $stage }}">{{ \App\Models\Proposal::STAGES[$stage] }}</span></td>
<td><a class="table-arrow" href="{{ route('proposals.show',$proposal) }}" aria-label="Ver proposta de {{ $proposal->customer->name }}">@include('components.icon',['name'=>'chevron'])</a></td></tr>
@empty<tr><td colspan="7"><div class="empty-state"><strong>Ninguém em campanha ainda.</strong><p>Quando uma venda entrar numa campanha, ela aparece aqui.</p></div></td></tr>@endforelse
</tbody></table></div>
{{ $proposals->links() }}
</section>
@endsection

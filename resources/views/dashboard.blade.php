@extends('layouts.app')
@section('title','Visão geral')
@section('content')
@php
$money=fn($value)=>'R$ '.number_format($value/100,2,',','.');
$number=fn($value)=>number_format($value,0,',','.');
$regionCities=$geo['map_cities'];
$go=fn($changes)=>route('dashboard',array_merge($filters,$changes));
$dashboardData=['series'=>[],'geo'=>$geo,'state'=>$filters['state'] ?: 'SP','city'=>$filters['city'],'neighborhood'=>$filters['neighborhood'],'states'=>\App\Services\Reports\DashboardService::STATES,'mapUrl'=>url('/painel/malha'),'neighborhoodUrl'=>url('/painel/bairros'),'assets'=>asset('assets/maps'),'months'=>$growth['months']];
$neighborhoods=collect($geo['neighborhoods'])->sortByDesc('plans')->take(8);
$maxNeighborhood=max(1,$neighborhoods->max('plans'));
$funnelBase=max(1,$summary['proposals_count']);
$general=$filters['base']==='gerais';
$growthEnd=end($growth['months']);
$signed=fn($value,$format)=>($value>0?'+':($value<0?'−':'')).$format(abs($value));
@endphp
<div class="dashboard-workspace dash-simple">
<div class="page-heading analytics-heading"><div><h1>{{ $isSales?'Meus resultados':'Oi, '.(collect(preg_split('/\s+/',trim(auth()->user()->name)))->first(fn($word)=>!str_ends_with($word,'.')) ?? 'equipe').'!' }}</h1><p>De {{ \Carbon\Carbon::parse($filters['date_from'])->format('d/m') }} a {{ \Carbon\Carbon::parse($filters['date_to'])->format('d/m/Y') }} · atualizado às {{ $generatedAt->format('H:i') }}</p></div><div class="analytics-heading-actions">@if(in_array(auth()->user()->role,['central_admin','manager','sales']) && auth()->user()->canUse('proposals.create'))<a class="button button-primary" href="{{ route('proposals.create') }}">@include('components.icon',['name'=>'plus']) Nova contratação</a>@endif @if(auth()->user()->canUse('dashboard.export'))<a class="button button-secondary" href="{{ route('dashboard.export',$filters) }}">@include('components.icon',['name'=>'download']) Exportar</a>@endif</div></div>
<form class="card analytics-filters" method="GET" id="dashboard-filters" action="{{ route('dashboard') }}">
<label class="field">De<input type="date" name="date_from" required value="{{ $filters['date_from'] }}" max="{{ $generatedAt->toDateString() }}"></label>
<label class="field">Até<input type="date" name="date_to" required value="{{ $filters['date_to'] }}" max="{{ $generatedAt->toDateString() }}"></label>
<label class="field">Unidade<select name="franchise_id"><option value="">Todas do meu acesso</option>@foreach($options['franchises'] as $option)<option value="{{ $option->id }}" @selected($filters['franchise_id']==$option->id)>{{ $option->name }}</option>@endforeach</select></label>
<label class="field" title="Planos gerais: toda a carteira, com a base antiga. Planos novos: só o que foi vendido pelo sistema.">Planos<select name="base"><option value="gerais" @selected($filters['base']==='gerais')>Planos gerais</option><option value="novos" @selected($filters['base']==='novos')>Planos novos</option></select></label>
<div class="analytics-filter-actions"><button class="button button-primary">Aplicar</button><a class="text-link" href="{{ route('dashboard') }}">Limpar</a></div>
<details class="dashboard-more-filters" @if($filters['employee_id'] || $filters['product_id'] || $filters['acquisition_source'] || $filters['city'] || $filters['neighborhood'] || $filters['category']!=='health_plan' || $filters['data_mode']!=='real') open @endif><summary>Mais filtros</summary><div class="dashboard-more-filter-grid">@unless($isSales)<label class="field">Funcionário<select name="employee_id"><option value="">Toda a equipe</option>@foreach($options['employees'] as $option)<option value="{{ $option->id }}" @selected($filters['employee_id']==$option->id)>{{ $option->name }}</option>@endforeach</select></label>@endunless
<label class="field">Produto<select name="product_id"><option value="">Todos os produtos</option>@foreach($options['products'] as $option)<option value="{{ $option->id }}" @selected($filters['product_id']==$option->id)>{{ $option->name }}</option>@endforeach</select></label>
<label class="field">Categoria<select name="category"><option value="health_plan" @selected($filters['category']==='health_plan')>Planos de saúde</option><option value="package" @selected($filters['category']==='package')>Pacotes</option><option value="all" @selected($filters['category']==='all')>Planos e pacotes</option></select></label>
<label class="field">Origem do cliente<select name="acquisition_source"><option value="">Todas as origens</option>@foreach(\App\Services\Attribution\TrackingSanitizer::sourceLabels() as $value=>$label)<option value="{{ $value }}" @selected($filters['acquisition_source']===$value)>{{ $label }}</option>@endforeach</select></label>
<input type="hidden" name="state" value="{{ $filters['state'] }}">
<label class="field">Cidade<select name="city"><option value="">Todas as cidades</option>@foreach($regionCities as $city)<option data-state="{{ $city['state'] }}" value="{{ $city['name'] }}" @selected(\App\Services\Reports\DashboardService::normalize($filters['city'])===\App\Services\Reports\DashboardService::normalize($city['name']))>{{ $city['name'] }}</option>@endforeach</select></label>
<label class="field">Bairro<input name="neighborhood" list="dashboard-neighborhood-options" maxlength="150" value="{{ $filters['neighborhood'] }}" placeholder="Todos"></label>
<datalist id="dashboard-neighborhood-options">@foreach(collect($geo['neighborhoods'])->where('missing',false) as $neighborhood)<option value="{{ $neighborhood['name'] }}">{{ $neighborhood['city'] }}</option>@endforeach</datalist>
@if(config('plans.demo'))<label class="field">Base de dados<select name="data_mode"><option value="real" @selected($filters['data_mode']==='real')>Dados reais</option><option value="demo" @selected($filters['data_mode']==='demo')>Demonstração</option></select></label>@else<input type="hidden" name="data_mode" value="real">@endif
</div></details>
</form>
@if($legacyPending)<section class="card dash-simplesvet dash-legacy" aria-labelledby="dash-legacy-title"><div><h2 id="dash-legacy-title">Pets para escolher: {{ $number($legacyPending) }}</h2><p class="dash-simple-note">{{ $legacyPending===1?'Um plano da base antiga está ativo':$number($legacyPending).' planos da base antiga estão ativos' }} na Vindi sem pet ligado aqui. Escolha o pet de cada um para o contrato aparecer na ficha do cliente.</p></div><a class="button button-primary" href="{{ route('legacy.pending') }}">Escolher os pets</a></section>@endif
@if($skippedBills->isNotEmpty())<section class="card dash-simplesvet" aria-labelledby="dash-skipped-title"><h2 id="dash-skipped-title">Faturas que a Vindi não gerou: {{ $number($skippedBills->count()) }}</h2><p class="dash-simple-note">A Vindi deixa de gerar a fatura do mês quando o dia de cobrança é mudado para antes do início do ciclo. Gere a fatura avulsa desse mês na Vindi e confira o dia de cobrança da assinatura.</p>
@foreach($skippedBills as $item)<div class="analytics-location-row"><div><strong><a href="{{ route('customers.show',$item['subscription']->customer_id) }}">{{ $item['subscription']->customer?->name }}</a></strong><small>Assinatura {{ $item['vindi'] }} na Vindi · sem a fatura de {{ $item['month'] }}</small></div></div>@endforeach
</section>@endif
@if($simplesvetPending->isNotEmpty())<section class="card dash-simplesvet" aria-labelledby="dash-simplesvet-title"><h2 id="dash-simplesvet-title">Pendências do SimplesVet</h2><p class="dash-simple-note">O robô parou nestes casos para não errar. Resolva o motivo e clique em “Tentar de novo”.</p>
@foreach($simplesvetPending as $item)<div class="analytics-location-row"><div><strong><a href="{{ route('customers.show',$item['subscription']->customer_id) }}#pet-{{ $item['subscription']->pet_id }}">{{ $item['subscription']->customer->name }} · {{ $item['subscription']->pet->name }}</a></strong><small>{{ $item['reason'] }}</small></div><form method="POST" action="{{ route('simplesvet.retry',$item['subscription']) }}">@csrf<button class="button button-secondary button-small">Tentar de novo</button></form></div>@endforeach
</section>@endif
@if($filters['data_mode']==='demo')<div class="alert alert-warning"><div>Só dados de demonstração. Eles não entram nos números reais.</div></div>@endif
@include('dashboard.overview')
@if($recentLeads->isNotEmpty())<section class="dash-leads" aria-labelledby="dash-leads-title"><div class="dash-leads-head"><h2 id="dash-leads-title">Leads novos</h2><a class="text-link" href="{{ route('leads.index',['situacao'=>'new']) }}">Ver todos</a></div><div class="dash-leads-grid">@foreach($recentLeads as $lead)<article class="dash-lead"><div class="dash-lead-top"><span class="dash-lead-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($lead->name,0,1)) }}</span><small title="Veio de: {{ $lead->origin()['label'] ?? 'Não informado' }}">@foreach($lead->origin()['logos'] ?? [] as $logo)<img src="{{ asset('assets/brand/'.$logo.'.svg') }}" alt="{{ ucfirst($logo) }}" width="15" height="15">@endforeach {{ $lead->created_at->locale('pt_BR')->diffForHumans() }}</small></div><h3>{{ $lead->name }}</h3><p>{{ $lead->campaignLabel() }}</p><div class="dash-lead-actions">@if($lead->whatsappUrl())<a class="button button-primary button-small" href="{{ $lead->whatsappUrl($lead->greeting()) }}" target="_blank" rel="noopener">WhatsApp</a>@endif<a class="button button-secondary button-small" href="{{ route('leads.index',['situacao'=>'new']) }}">Abrir</a></div></article>@endforeach</div></section>@endif
<div class="dash-simple-grid">
<section class="card"><h2>Da proposta ao pagamento</h2>
@foreach([['proposals_count','Propostas oferecidas'],['cohort_accepted','Aceitas'],['cohort_documents','Contratos assinados'],['cohort_initial_paid','Primeiro pagamento']] as [$key,$label])<div class="analytics-funnel-step"><div><span>{{ $label }}</span><strong>{{ $number($summary[$key]) }}</strong></div><div class="analytics-progress"><span style="width:{{ min(100,$summary[$key]*100/$funnelBase) }}%"></span></div></div>@endforeach
</section>
<section class="card" id="produtos"><h2>Planos</h2>
@forelse($plans as $plan)<div class="analytics-location-row"><div><strong><i class="dash-dot" style="--dot:{{ $plan['slot']===null?'var(--c-other)':'var(--slot-'.$plan['slot'].')' }}" aria-hidden="true"></i>{{ $plan['name'] }}</strong><small>{{ collect([$general ? $money($plan['mrr_cents']).' por mês' : null, $plan['sales'] || ! $general ? $number($plan['sales']).' fechado(s) no período' : null])->filter()->implode(' · ') }}</small></div><b>{{ $number($plan['active']) }}<small>ativo(s)</small></b></div>@empty<div class="analytics-empty">Nenhum plano no período.</div>@endforelse
</section>
<section class="card dash-simple-map" id="mapa"><h2>Mapa dos planos ativos</h2>
<div class="analytics-map-toolbar"><div class="analytics-map-tabs" role="tablist" aria-label="Abrangência do mapa"><button type="button" role="tab" id="map-regional-tab" aria-controls="map-regional-panel" aria-selected="true" data-map-tab="regional">Prudente e região</button><button type="button" role="tab" id="map-bairros-tab" aria-controls="map-bairros-panel" aria-selected="false" data-map-tab="bairros">Bairros</button></div>@if($filters['city'])<div class="analytics-map-breadcrumb"><a href="{{ $go(['state'=>null,'city'=>null,'neighborhood'=>null]) }}">Prudente e região</a><span>›</span><span>{{ $filters['city'] }}</span></div>@endif</div>
<div id="map-regional-panel" role="tabpanel" aria-labelledby="map-regional-tab"><div id="dashboard-region-map" class="analytics-map" aria-label="Mapa regional por município"><p class="analytics-loading">Carregando mapa regional…</p></div><div class="analytics-map-legend" data-map-legend aria-label="Legenda de planos ativos"></div></div>
<div id="map-bairros-panel" role="tabpanel" aria-labelledby="map-bairros-tab" hidden><div id="dashboard-neighborhood-map" class="analytics-map" aria-label="Mapa de concentração por bairro"><p class="analytics-loading">Carregando bairros…</p></div><div class="analytics-map-legend" data-map-legend aria-label="Legenda de planos ativos"></div><p class="analytics-note map-credits"><a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">© OpenStreetMap</a></p></div>
</section>
<section class="card" id="territorio"><h2>Prudente e região</h2>
@forelse(collect($geo['cities'])->take(6) as $city)<a class="analytics-location-row" href="{{ $go(['state'=>$city['state'],'city'=>$city['city'],'neighborhood'=>null]) }}"><div><strong>{{ $city['city'] }}</strong><small>{{ $number($city['customers']) }} tutor(es)</small></div><b>{{ $number($city['plans']) }}<small>plano(s) ativo(s)</small></b></a>@empty<div class="analytics-empty">Sem planos ativos na região.</div>@endforelse
@if($geo['missing_address'])<p class="dash-simple-note">{{ $number($geo['missing_address']) }} plano(s) sem cidade no cadastro.</p>@endif
</section>
<section class="card"><h2>Bairros{{ $filters['city']?' em '.$filters['city']:'' }}</h2>
@forelse($neighborhoods as $n)<a class="dash-simple-bar" href="{{ $go(['state'=>$n['state'],'city'=>$n['city'],'neighborhood'=>$n['missing']?null:$n['name']]) }}" @if($filters['neighborhood'] && \App\Services\Reports\DashboardService::normalize($filters['neighborhood'])===\App\Services\Reports\DashboardService::normalize($n['name'])) aria-current="true" @endif><div><span>{{ $n['name'] }}@unless($filters['city'])<small> · {{ $n['city'] }}</small>@endunless</span><strong>{{ $number($n['plans']) }}</strong></div><div class="analytics-progress"><span style="width:{{ max(6,$n['plans']*100/$maxNeighborhood) }}%"></span></div></a>@empty<div class="analytics-empty">Sem bairros com planos ativos.</div>@endforelse
@if($filters['neighborhood'])<a class="text-link" href="{{ $go(['neighborhood'=>null]) }}">Ver todos os bairros</a>@endif
</section>
<section class="card" id="origem"><h2>Como chegam até nós</h2>
@forelse($insights['origins'] as $origin)<a class="analytics-location-row" href="{{ $go(['acquisition_source'=>$origin['source']]) }}"><div><strong>{{ $origin['label'] }}</strong></div><b>{{ $number($origin['sales']) }}<small>contrato(s)</small></b></a>@empty<div class="analytics-empty">Nenhuma origem registrada no período.</div>@endforelse
</section>
<section class="card"><h2>Equipe</h2>
@forelse($ranking as $row)<div class="analytics-location-row"><div><strong>{{ $row['name'] }}</strong><small>Comissão: @if($row['id'] && in_array(auth()->user()->role,['central_admin','manager','finance','sales']))<a class="text-link" href="{{ route('commissions.index',['beneficiary'=>$row['id'],'date_from'=>$filters['date_from'],'date_to'=>$filters['date_to']]) }}">{{ $money($row['commission_cents']) }}</a>@else{{ $money($row['commission_cents']) }}@endif</small></div><b>{{ $number($row['sales']) }}<small>contrato(s)</small></b></div>@empty<div class="analytics-empty">Nenhuma venda no período.</div>@endforelse
</section>
</div>
</div>
<script type="application/json" id="dashboard-data">@json($dashboardData)</script>
@push('scripts')<script defer src="{{ asset('assets/analytics.js') }}?v={{ filemtime(public_path('assets/analytics.js')) }}"></script>@endpush
@endsection

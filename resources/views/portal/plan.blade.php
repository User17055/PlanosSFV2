@extends('layouts.app')
@section('title',$subscription->productVersion->name)
@section('content')
@php
$finalDocument=$subscription->contractDocuments->where('status','valid')->where('kind','final_contract')->sortByDesc('version')->first();
$cancellationPending=data_get($subscription,'cancellationRequest.status')==='pending';
$planNotices=$notices[$subscription->id] ?? [];
$cycle=['monthly'=>'por mês','annual'=>'por ano','one_time'=>'pagamento único'][$subscription->productVersion->billing_cycle] ?? '';
$periods=['calendar_month'=>'por mês','monthly_cycle'=>'por ciclo mensal','contract_year'=>'por ano de contrato','lifetime'=>'durante a vigência'];
$rules=$subscription->productVersion->benefitRules;
$terminal=$subscription->isTerminal();
$contractedAt=$subscription->proposal?->accepted_at ?? $subscription->created_at;
$endedAt=$subscription->cancelled_at ?? $subscription->ends_at;
$petPlans=$customer->subscriptions->where('pet_id',$pet->id)->sortByDesc('id')->values();
@endphp
<a class="back-link" href="{{ route('portal.pet',$pet) }}">← {{ $pet->name }}</a>
<section class="portal-hero plan-hero-nav">
<div class="portal-hero-text">
<span class="portal-hero-eyebrow">Plano de {{ $pet->name }}</span>
<h1>{{ $subscription->productVersion->name }} <span class="plan-hero-status">@include('components.status',['status'=>$subscription->status])</span></h1>
<ul class="portal-hero-facts">
<li><strong>{{ $money($subscription->price_cents) }}</strong> {{ $cycle }}</li>
<li>Contratado em <strong>{{ $date($contractedAt) }}</strong></li>
@if($terminal && $endedAt)<li>Encerrado em <strong>{{ $date($endedAt) }}</strong></li>@elseif($subscription->ends_at)<li>{{ $subscription->status==='scheduled_cancellation'?'Encerra em':'Vigente até' }} <strong>{{ $date($subscription->ends_at) }}</strong></li>@endif
<li>Contrato <strong>{{ $subscription->number }}</strong></li>
</ul>
</div>
<div class="portal-hero-actions">
@if($finalDocument)<a class="button portal-hero-cta" href="{{ route('contracts.download',$finalDocument) }}">@include('components.icon',['name'=>'download']) Baixar contrato</a>@else<a class="button portal-hero-ghost" href="{{ route('proposals.show',$subscription->proposal_id) }}">Ver contratação</a>@endif
@unless($cancellationPending || $terminal)<button type="button" class="button portal-hero-ghost" data-dialog-open="portal-cancel-{{ $subscription->id }}">Solicitar cancelamento</button>@endunless
</div>
</section>
@if($cancellationPending)<p class="portal-note">@include('components.icon',['name'=>'clock']) Seu pedido de cancelamento está em análise pela unidade. Até a resposta, nada muda.</p>
@elseif($terminal)<p class="portal-note">@include('components.icon',['name'=>'info']) Este plano está encerrado. Os dados abaixo ficam guardados como histórico.</p>
@else @foreach($planNotices as $notice)<p class="portal-note">@include('components.icon',['name'=>'info']) {{ $notice }}</p>@endforeach @endif

<section class="portal-section" aria-labelledby="plan-benefits-title"><h2 class="portal-section-title" id="plan-benefits-title">{{ $terminal?'O que o plano cobria':'O que está disponível' }}</h2>
<div class="card plan-section">
@if($rules->isEmpty())<p class="fine-print">Este plano não tem benefícios cadastrados.</p>
@else<ul class="plan-benefits">
@foreach($rules as $rule)
@php
$benefit=$benefits[$subscription->id][$rule->id] ?? null;
$limit=$rule->quantity_limit;
$remaining=data_get($benefit,'remaining');
$showMeter=$benefit && !$planNotices && $limit!==null && $remaining!==null;
$percent=$showMeter && $limit>0 ? max(0,min(100,round($remaining/$limit*100))) : 0;
$description=$rule->coverage_type==='discount' ? (str_starts_with($rule->name,'Até ') || ($rule->discount_basis_points ?? 0)<=0 ? 'Desconto conforme contrato' : number_format($rule->discount_basis_points/100,0,',','.').'% de desconto') : ($limit!==null ? 'Até '.$limit.' '.($periods[$rule->period_type] ?? '') : 'Conforme contrato');
@endphp
<li class="plan-benefit"><div class="plan-benefit-head"><div><strong>{{ $rule->name }}</strong><small>{{ $description }}</small></div>@if($benefit && !$planNotices && in_array($benefit['tone'],['ok','used','wait'],true))<span class="portal-benefit-state is-{{ $benefit['tone'] }}">{{ $benefit['label'] }}</span>@endif</div>
@if($showMeter)<div class="plan-meter" role="img" aria-label="{{ $remaining }} de {{ $limit }} disponíveis"><span style="width:{{ $percent }}%"></span></div><p class="plan-benefit-balance"><strong>{{ $remaining }} de {{ $limit }}</strong> disponíveis{{ data_get($benefit,'periodEnd') && $rule->period_type!=='lifetime' ? ' · renova em '.\Illuminate\Support\Carbon::parse($benefit['periodEnd'])->timezone('America/Sao_Paulo')->format('d/m/Y') : '' }}</p>
@elseif($benefit && !$planNotices && $limit===null && $benefit['tone']==='ok')<p class="plan-benefit-balance"><strong>Sem limite</strong> de quantidade</p>
@elseif($benefit && !$planNotices && $benefit['detail'])<p class="plan-benefit-balance">{{ $benefit['detail'] }}</p>@endif
</li>
@endforeach
</ul>@endif
</div></section>

<section class="portal-section" aria-labelledby="plan-usage-title"><h2 class="portal-section-title" id="plan-usage-title">Histórico de uso ({{ $subscription->usages->count() }})</h2>
<div class="card plan-section">
@if($subscription->usages->isEmpty())<p class="fine-print">Nenhum benefício foi utilizado neste plano ainda.</p>
@else<ul class="portal-list">@foreach($subscription->usages as $usage)<li><div><strong>{{ $usage->benefitRule?->name ?? data_get($usage->rule_snapshot,'name','Benefício') }}{{ $usage->quantity>1?' × '.$usage->quantity:'' }}</strong><small>{{ $date($usage->consumed_at) }}{{ $usage->servicingFranchise?' · '.$usage->servicingFranchise->name:'' }}</small></div>@if($usage->reversals->isNotEmpty())<span class="badge badge-gray">Estornado</span>@endif</li>@endforeach</ul>@endif
</div></section>

<section class="portal-section" aria-labelledby="plan-others-title"><h2 class="portal-section-title" id="plan-others-title">Planos de {{ $pet->name }}</h2>
<div class="card plan-section">
<ul class="plan-timeline">@foreach($petPlans as $other)
<li class="{{ $other->id===$subscription->id?'is-current':'' }}"><div><a href="{{ route('portal.plan',[$pet,$other]) }}">{{ $other->productVersion->name }}</a><small>{{ $date($other->effective_start_at ?? $other->created_at) }} → {{ $other->isTerminal() ? $date($other->cancelled_at ?? $other->ends_at) : ($other->ends_at ? $date($other->ends_at) : 'em andamento') }}</small></div>@include('components.status',['status'=>$other->status])</li>
@endforeach</ul>
</div></section>

@unless($cancellationPending || $terminal)<dialog class="modal" id="portal-cancel-{{ $subscription->id }}"><form method="POST" action="{{ route('subscriptions.request-cancellation',$subscription) }}">@csrf<div class="modal-heading"><h2>Solicitar cancelamento</h2><button type="button" class="icon-button" data-dialog-close aria-label="Fechar">@include('components.icon',['name'=>'close'])</button></div><p>{{ $subscription->productVersion->name }} · {{ $pet->name }}</p><div class="alert alert-warning"><div>A unidade vai analisar o pedido. Enviar não encerra o plano na hora nem muda pagamentos, documentos ou benefícios.</div></div><label class="field">Conte-nos o motivo <span>*</span><textarea name="reason" required minlength="10" rows="3"></textarea></label><div class="form-actions"><button type="button" class="button button-secondary" data-dialog-close>Voltar</button><button class="button button-primary">Enviar pedido</button></div></form></dialog>@endunless
@endsection

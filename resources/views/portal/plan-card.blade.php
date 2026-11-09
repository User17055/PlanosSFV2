@php
$finalDocument=$subscription->contractDocuments->where('status','valid')->where('kind','final_contract')->sortByDesc('version')->first();
$cycle=['monthly'=>'por mês','annual'=>'por ano','one_time'=>'pagamento único'][$subscription->productVersion->billing_cycle] ?? '';
$planNotices=$notices[$subscription->id] ?? [];
$cancellationPending=data_get($subscription,'cancellationRequest.status')==='pending';
@endphp
<details class="card plan-card" open>
<summary>
<span class="plan-card-top"><h3>{{ $subscription->productVersion->name }}</h3>@include('components.status',['status'=>$subscription->status])</span>
<span class="plan-card-meta">{{ $money($subscription->price_cents) }} {{ $cycle }} · contratado em {{ $date($subscription->proposal?->accepted_at ?? $subscription->created_at) }}@if($subscription->ends_at) · {{ $subscription->status==='scheduled_cancellation'?'encerra em':'vigente até' }} {{ $date($subscription->ends_at) }}@endif</span>
</summary>
<div class="plan-card-body">
@if($cancellationPending)<p class="portal-note">@include('components.icon',['name'=>'clock']) Pedido de cancelamento em análise pela unidade.</p>
@elseif($planNotices)<p class="portal-note">@include('components.icon',['name'=>'info']) {{ $planNotices[0] }}</p>@endif
@php $planBenefits=$benefits[$subscription->id] ?? []; @endphp
@if($planBenefits)<ul class="plan-benefits" aria-label="Benefícios do plano">@foreach($subscription->productVersion->benefitRules as $rule)@continue(! isset($planBenefits[$rule->id]))@php $state=$planBenefits[$rule->id]; @endphp<li class="plan-benefit is-{{ $state['tone'] }}"><strong>{{ $rule->name }}</strong><span class="plan-benefit-state">{{ $state['label'] }}</span>@if($state['detail'])<small>{{ $state['detail'] }}</small>@endif</li>@endforeach</ul>@endif
<div class="plan-card-actions"><a class="button button-primary button-small" href="{{ route('portal.plan',[$pet,$subscription]) }}">Ver plano, saldo e histórico</a>@if($finalDocument)<a class="button button-secondary button-small" href="{{ route('contracts.download',$finalDocument) }}">@include('components.icon',['name'=>'download']) Baixar contrato</a>@endif</div>
</div>
</details>

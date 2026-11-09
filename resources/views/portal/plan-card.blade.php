@php
// Mesmo modelo do cartão do pet: só o básico; benefícios, saldo, histórico e contrato ficam em "Ver plano".
$cycle=['monthly'=>'por mês','annual'=>'por ano','one_time'=>'pagamento único'][$subscription->productVersion->billing_cycle] ?? '';
$planNotices=$notices[$subscription->id] ?? [];
$cancellationPending=data_get($subscription,'cancellationRequest.status')==='pending';
$statusLabel=['active'=>'Ativo','awaiting_regularization'=>'Aguardando ativação','suspended'=>'Suspenso','scheduled_cancellation'=>'Encerramento agendado'][$subscription->status] ?? $subscription->status;
$facts=collect([trim($money($subscription->price_cents).' '.$cycle),'Desde '.$date($subscription->proposal?->accepted_at ?? $subscription->created_at),
    $subscription->ends_at?($subscription->status==='scheduled_cancellation'?'Encerra em ':'Vigente até ').$date($subscription->ends_at):null])->filter();
@endphp
<a class="card portal-pet-link portal-plan-link" href="{{ route('portal.plan',[$pet,$subscription]) }}">
<div class="portal-pet-info"><div class="portal-pet-head"><h3>{{ $subscription->productVersion->name }}</h3><span class="portal-pet-pills"><span class="portal-pet-pill{{ $subscription->status==='active'?'':' is-waiting' }}">{{ $statusLabel }}</span></span></div>
<ul class="portal-pet-tags">@foreach($facts as $fact)<li>{{ $fact }}</li>@endforeach</ul>
@if($cancellationPending)<p class="portal-pet-plans">Pedido de cancelamento em análise pela unidade.</p>@elseif($planNotices)<p class="portal-pet-plans">{{ $planNotices[0] }}</p>@endif
<span class="portal-pet-go portal-pet-go-bottom">Ver plano @include('components.icon',['name'=>'arrow'])</span></div>
</a>

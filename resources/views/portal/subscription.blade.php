@php
$finalDocument=$subscription->contractDocuments->where('status','valid')->where('kind','final_contract')->sortByDesc('version')->first();
$cancellationPending=data_get($subscription,'cancellationRequest.status')==='pending';
$subscriptionNotices=$notices[$subscription->id] ?? [];
$cycle=['monthly'=>'por mês','annual'=>'por ano','one_time'=>'pagamento único'][$subscription->productVersion->billing_cycle] ?? '';
$periods=['calendar_month'=>'por mês','monthly_cycle'=>'por ciclo mensal','contract_year'=>'por ano de contrato','lifetime'=>'durante a vigência'];
$rules=$subscription->productVersion->benefitRules;
@endphp
<div class="portal-subscription" id="cuidado-{{ $subscription->id }}">
<div class="portal-subscription-title"><h4>{{ $subscription->productVersion->name }}</h4>@include('components.status',['status'=>$subscription->status])</div>
<p class="portal-subscription-meta">{{ $money($subscription->price_cents) }} {{ $cycle }} · {{ $subscription->effective_start_at?'Vigente desde '.$date($subscription->effective_start_at):'Vigência ainda não iniciada' }}@if($subscription->ends_at) · {{ $subscription->status==='scheduled_cancellation'?'Encerra em':'Termina em' }} {{ $date($subscription->ends_at) }}@endif</p>
@if($cancellationPending)<p class="portal-note">@include('components.icon',['name'=>'clock']) Seu pedido de cancelamento está em análise pela unidade. Até a resposta, nada muda.</p>
@else @foreach($subscriptionNotices as $notice)<p class="portal-note">@include('components.icon',['name'=>'info']) {{ $notice }}</p>@endforeach @endif
@if($rules->isNotEmpty())<details class="portal-disclosure"><summary>O que o plano cobre ({{ $rules->count() }})</summary><ul class="portal-list">
@foreach($rules as $rule)
@php $benefit=$benefits[$subscription->id][$rule->id] ?? null; @endphp
<li><div><strong>{{ $rule->name }}</strong><small>@if($rule->coverage_type==='discount'){{ str_starts_with($rule->name,'Até ')?'Desconto conforme contrato':number_format(($rule->discount_basis_points ?? 0)/100,0,',','.').'% de desconto' }}@elseif($rule->quantity_limit!==null)Até {{ $rule->quantity_limit }} {{ $periods[$rule->period_type] ?? '' }}@else Conforme contrato @endif</small></div>
{{-- Enquanto a contratação inteira está em espera, o aviso acima já explica; a situação de cada item só aparece depois. --}}
@if($benefit && !$subscriptionNotices && in_array($benefit['tone'],['ok','used','wait'],true))<span class="portal-benefit-state is-{{ $benefit['tone'] }}">{{ $benefit['label'] }}@if($benefit['detail'])<small>{{ $benefit['detail'] }}</small>@endif</span>@endif</li>
@endforeach
</ul></details>@endif
@if($subscription->usages->isNotEmpty())<details class="portal-disclosure"><summary>Histórico de uso ({{ $subscription->usages->count() }})</summary><ul class="portal-list">@foreach($subscription->usages->take(10) as $usage)<li><div><strong>{{ $usage->benefitRule?->name ?? data_get($usage->rule_snapshot,'name','Benefício') }}{{ $usage->quantity>1?' × '.$usage->quantity:'' }}</strong><small>{{ $date($usage->consumed_at) }}{{ $usage->servicingFranchise?' · '.$usage->servicingFranchise->name:'' }}</small></div>@if($usage->reversals->isNotEmpty())<span class="badge badge-gray">Estornado</span>@endif</li>@endforeach</ul>@if($subscription->usages->count()>10)<p class="fine-print">Mostrando os 10 usos mais recentes.</p>@endif</details>@endif
<div class="portal-subscription-actions">@if($finalDocument)<a class="button button-secondary button-small" href="{{ route('contracts.download',$finalDocument) }}">@include('components.icon',['name'=>'download']) Baixar contrato</a>@else<a class="button button-secondary button-small" href="{{ route('proposals.show',$subscription->proposal_id) }}">Ver contratação</a>@endif
@unless($cancellationPending)<button type="button" class="text-button muted portal-cancel" data-dialog-open="portal-cancel-{{ $subscription->id }}">Solicitar cancelamento</button>@endunless</div>
@unless($cancellationPending)<dialog class="modal" id="portal-cancel-{{ $subscription->id }}"><form method="POST" action="{{ route('subscriptions.request-cancellation',$subscription) }}">@csrf<div class="modal-heading"><h2>Solicitar cancelamento</h2><button type="button" class="icon-button" data-dialog-close aria-label="Fechar">@include('components.icon',['name'=>'close'])</button></div><p>{{ $subscription->productVersion->name }} · {{ $pet->name }}</p><div class="alert alert-warning"><div>A unidade vai analisar o pedido. Enviar não encerra o plano na hora nem muda pagamentos, documentos ou benefícios.</div></div><label class="field">Conte-nos o motivo <span>*</span><textarea name="reason" required minlength="10" rows="3"></textarea></label><div class="form-actions"><button type="button" class="button button-secondary" data-dialog-close>Voltar</button><button class="button button-primary">Enviar pedido</button></div></form></dialog>@endunless
</div>

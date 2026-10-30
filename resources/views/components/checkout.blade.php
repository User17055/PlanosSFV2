@php $initial=$subscription->invoices->firstWhere('is_initial',true); @endphp
@if($subscription->productVersion->billing_cycle==='monthly' && $initial?->status!=='paid' && !$subscription->isTerminal())<p class="fine-print">Pague a 1ª parcela para ativar o plano.</p>@endif
@if($initial?->status==='paid')
<p>Pagamento confirmado. Acompanhe a ativação e as carências no portal.</p>
@elseif(in_array($initial?->status,['cancelled','refunded','disputed'],true) || $subscription->isTerminal())
<p>Consulte a situação atual do contrato e dos pagamentos no portal.</p>
@elseif(app(\App\Services\Integrations\VindiCheckoutService::class)->available($subscription))
<p>{{ ($autoSubmit??false)?'Contrato assinado. Levando você para o pagamento seguro na Vindi…':'Seu contrato está assinado. Continue para o pagamento seguro na Vindi.' }}</p>
@php $methods=app(\App\Services\Integrations\VindiCheckoutService::class)->paymentMethods(); $chosen=$subscription->payment_method_code ?? array_key_first($methods); @endphp
<form method="POST" action="{{ route('subscriptions.checkout',$subscription) }}" @if(($autoSubmit??false) && count($methods)===1) data-auto-submit @endif>@csrf
@if(count($methods)>1)<fieldset class="payment-methods"><legend>Como você prefere pagar?</legend>@foreach($methods as $code=>$label)<label class="check-label"><input type="radio" name="payment_method" value="{{ $code }}" required @checked($chosen===$code)><span><strong>{{ $label }}</strong><small>{{ str_contains($code,'credit_card')?'A entrada é cobrada agora e as mensalidades, automaticamente no cartão.':'A entrada é paga agora e as mensalidades chegam por e-mail, com vencimento até o dia '.config('plans.billing.due_day').'.' }}</small></span></label>@endforeach</fieldset>@else<input type="hidden" name="payment_method" value="{{ $chosen }}">@endif
<button class="button button-primary full-width" data-submit-label="Preparando pagamento...">Pagar com Vindi @include('components.icon',['name'=>'arrow'])</button></form>
<p class="fine-print">Você continuará na página de pagamento da Vindi. A confirmação aparecerá no seu portal.</p>
@else
<p>{{ $subscription->document_status==='valid'?'Seu contrato está registrado. O pagamento online está em preparação; fale com a unidade para acompanhar a cobrança.':'Conclua a assinatura e aguarde a preparação do contrato para continuar para o pagamento.' }}</p>
@endif

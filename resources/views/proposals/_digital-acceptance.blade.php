@php
$preview=$preview ?? false;
$signerName=$preview?$signerName:$proposal->customer->name;
$petName=$preview?$petName:$proposal->pet->name;
$acceptVersion=$preview?$version:$proposal->productVersion;
$acceptPrice=$preview?$version->price_cents:$proposal->price_cents;
@endphp
<form method="POST" action="{{ $preview?'#':route('proposals.accept',$proposal) }}" @if(!$preview) data-signature-form @endif @if(!$preview && ($nativeContract??false)) data-contract-signing-form @endif>
@if(!$preview)
@csrf
<input type="hidden" name="review_fingerprint" value="{{ $reviewFingerprint ?? '' }}">
<input type="hidden" name="signature_data" data-signature-value>
<input type="hidden" name="device_info" data-device-info>
@endif
<fieldset style="border:0;padding:0;margin:0;min-width:0" @disabled($preview)>
<label class="field">Confirme seu nome completo<input name="signer_name" required maxlength="180" autocomplete="name" value="{{ old('signer_name',$signerName) }}"></label>
<label class="check-label"><input type="checkbox" required name="accepted" value="1"><span>Li o contrato integral e aceito a contratação de {{ $acceptVersion->name }} para {{ $petName }}, por R$ {{ number_format($acceptPrice/100,2,',','.') }}, com a periodicidade, os limites e as carências apresentados acima.</span></label>
<div class="signature-label"><strong>Assine com o dedo ou o mouse na área abaixo</strong><button type="button" class="text-button" data-clear-signature>Limpar assinatura</button></div>
<canvas class="signature-canvas" data-signature-canvas aria-label="Área para desenhar sua assinatura"></canvas>
<p class="fine-print">A confirmação e a assinatura são obrigatórias. Junto com elas registramos a data e a hora, o endereço IP e os dados do aparelho usado, como prova do aceite.</p>
<p class="fine-print">O aceite registra a versão contratada e gera o documento final. Em seguida, você segue para o pagamento. A ativação depende da confirmação do primeiro pagamento e da validação dos demais requisitos.</p>
@if(!$preview && ($nativeContract??false))<p class="fine-print" data-signing-review-hint>O botão é liberado após carregar o PDF para conferência.</p>@endif
<button class="button button-primary" data-submit-label="Registrando seu aceite..." @if(!$preview && ($nativeContract??false)) data-requires-review @disabled($signatureReviewRequired??true) @endif>Confirmar assinatura e gerar contrato @include('components.icon',['name'=>'check'])</button>
</fieldset></form>

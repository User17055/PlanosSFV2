@php
$nativeContract=(bool)data_get($proposal->snapshot,'version.contract_layout.source');
$reviewUrl=route('proposals.preview-pdf',['proposal'=>$proposal,'fingerprint'=>$reviewFingerprint]);
$finalContract=$proposal->subscription?->contractDocuments->where('kind','final_contract')->where('status','valid')->sortByDesc('version')->first();
$documentSigned=$proposal->accepted_at || $finalContract?->accepted_at;
$awaitingFinal=$proposal->accepted_at && !$finalContract;
@endphp
<section class="signing-contract" id="contrato-revisao" @if($nativeContract && !$awaitingFinal) data-contract-review data-pdf-url="{{ $reviewUrl }}" @endif>
<div class="signing-review-heading"><div><h3>{{ $documentSigned?'Seu contrato':'Confira o contrato antes de assinar' }}</h3><p>{{ $documentSigned?'Consulte o documento e acompanhe a formalização abaixo.':'Confira nome, CPF, endereço, dados do pet e todas as condições.' }}</p></div></div>
@if($awaitingFinal)
<p class="signing-document-status">Sua assinatura está registrada. O PDF final está em preparação. Acompanhe a conclusão abaixo.</p>
@elseif($nativeContract)
<div class="signing-document-actions"><a class="button button-secondary button-small" href="{{ $reviewUrl }}" target="_blank" rel="noopener" data-contract-open>@include('components.icon',['name'=>'external']) Abrir PDF em nova aba</a><button type="button" class="text-button" data-contract-retry>Carregar novamente</button></div>
<div class="signing-document-status" role="status" aria-live="polite" data-contract-status>Preparando o PDF para conferência…</div>
<iframe title="Contrato completo para conferência" class="signing-pdf-frame" data-contract-frame hidden></iframe>
<noscript><p class="alert alert-info">Abra o PDF pelo botão acima. Depois de conferir, atualize esta página para liberar a assinatura.</p></noscript>
@else
<div class="contract-text">{{ app(\App\Services\Contracts\ContractTemplate::class)->render((string)data_get($proposal->snapshot,'version.contract_template',''),$proposal->snapshot,['accepted_at'=>$proposal->accepted_at?->toIso8601String(),'contract_number'=>$proposal->subscription?->number ?? 'A gerar no aceite']) }}</div>
@endif
</section>

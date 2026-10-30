@php
$subscription=$proposal->subscription;
$finalDocument=$subscription?->contractDocuments->where('kind','final_contract')->where('status','valid')->sortByDesc('version')->first();
$signedAt=$proposal->accepted_at ?? $finalDocument?->accepted_at;
$signed=$signedAt!==null;
$expired=!$proposal->locked_at && $proposal->expires_at->isPast();
$closed=$proposal->status==='cancelled';
@endphp
<div class="signing-progress" aria-label="Etapas da contratação">
<div class="{{ $signed?'is-complete':'is-current' }}"><span>1</span><strong>Conferir contrato</strong><small>{{ $signed?'Conferido no aceite':'Dados e condições' }}</small></div>
<div class="{{ $signed?'is-complete':'' }}"><span>2</span><strong>{{ $signed?'Assinatura registrada':'Confirmar assinatura' }}</strong><small>{{ $signed?$signedAt->timezone('America/Sao_Paulo')->format('d/m/Y H:i'):'Pelo titular' }}</small></div>
<div class="{{ $finalDocument?'is-complete':'' }}"><span>3</span><strong>{{ $finalDocument?'PDF disponível':'Documento final' }}</strong><small>{{ $finalDocument?'Salvo no seu histórico':($signed?'Aguardando geração':'Após a assinatura') }}</small></div>
</div>
@if($expired)<div class="alert alert-warning"><div><strong>Proposta vencida.</strong><p>Peça uma nova à unidade.</p></div></div>@endif
@if($closed)<div class="alert alert-warning"><div>Proposta cancelada.</div></div>@endif
@if(!$proposal->locked_at && !$expired && !$closed && !$proposal->productVersion->isOffered())<div class="alert alert-warning"><div>Plano fora de venda. Peça uma nova proposta à unidade.</div></div>@endif
@if($finalDocument)<div class="alert alert-success signing-final"><div><strong>Assinado!</strong><p>Falta o pagamento para ativar o plano.</p></div><a class="button button-primary button-small" href="{{ route('contracts.download',$finalDocument) }}">@include('components.icon',['name'=>'download']) Baixar contrato assinado</a></div>@endif
@push('scripts')<script defer src="{{ asset('assets/signing.js') }}?v={{ filemtime(public_path('assets/signing.js')) }}"></script>@endpush

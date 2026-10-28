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
@if($expired)<div class="alert alert-warning"><div><strong>Esta proposta expirou.</strong><p>Solicite uma nova proposta à unidade para conferir as condições atuais.</p></div></div>@endif
@if($closed)<div class="alert alert-warning"><div>Esta proposta foi cancelada. O histórico permanece disponível.</div></div>@endif
@if(!$proposal->locked_at && !$expired && !$closed && !$proposal->productVersion->isOffered())<div class="alert alert-warning"><div>Este plano não está mais disponível para novas contratações. Solicite uma nova proposta à unidade.</div></div>@endif
@if(!$proposal->locked_at && !$proposal->customer->contact_verified_at)<div class="alert alert-warning"><div>O contato do titular precisa ser confirmado antes de assinar. Acesse o portal do tutor ou solicite apoio à unidade.</div></div>@endif
@if($finalDocument)<div class="alert alert-success signing-final"><div><strong>Assinatura concluída.</strong><p>A ativação do plano também depende do pagamento e dos requisitos da contratação.</p></div><a class="button button-primary button-small" href="{{ route('contracts.download',$finalDocument) }}">@include('components.icon',['name'=>'download']) Baixar contrato assinado</a></div>@endif
@push('scripts')<script defer src="{{ asset('assets/signing.js') }}?v={{ filemtime(public_path('assets/signing.js')) }}"></script>@endpush

@extends('layouts.public')
@section('title','Contrato e assinatura')
@section('content')
<div class="registration-intro"><h1>Contrato e assinatura</h1><p>{{ $version->name }} · R$ {{ number_format($version->price_cents/100,2,',','.') }} por mês</p></div>
<section class="card form-card">
<p>Você está conferindo a tela com o acesso da equipe. Para o tutor, este link começa pelo preenchimento dos dados dele e do pet e depois apresenta o contrato preenchido para aceite.</p>
<p>Os campos abaixo permanecem com os marcadores. Esta visualização não cria cadastro nem registra assinatura.</p>
<p><a class="text-link" href="{{ route('attribution_links.contract-preview-pdf',['token'=>$token]) }}" target="_blank" rel="noopener">Abrir contrato em outra aba</a></p>
<iframe title="Contrato do plano com campos para preenchimento" src="{{ route('attribution_links.contract-preview-pdf',['token'=>$token]) }}" style="width:100%;height:850px;border:1px solid #d9d9d9;margin-top:20px"></iframe>
</section>
<section class="card form-card section-gap"><h2>Seu aceite, com transparência.</h2><p>Esta é a área de aceite apresentada ao tutor após o preenchimento e a revisão do contrato.</p>
@include('proposals._digital-acceptance')
<p class="fine-print">O botão fica desabilitado nesta visualização da equipe. Somente o titular autenticado pode registrar o aceite.</p>
</section>
@endsection

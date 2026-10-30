@extends('layouts.app')
@section('title','Link para preenchimento do tutor')
@section('content')
<a class="back-link" href="{{ route('proposals.index') }}">← Propostas</a>
<div class="page-heading"><div><h1>Envie o link para o tutor</h1><p>{{ $link->productVersion->name }} · {{ $link->originatingFranchise->name }}</p></div></div>
<section class="card form-card">
<p>O tutor preencherá seus dados e os do pet pelo link. O sistema criará o cadastro e a proposta automaticamente, para então apresentar o contrato para revisão e aceite.</p>
<label class="field">Link para preenchimento<input id="registration-link" value="{{ $url }}" readonly></label>
<button type="button" class="button button-primary" data-copy-target="registration-link">Copiar link</button>
<p class="fine-print">Válido até {{ $link->expires_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}. Quem já possui cadastro deve entrar com seu acesso. O preenchimento não confirma pagamento nem substitui o aceite.</p>
<a class="text-link" href="{{ route('attribution_links.index') }}">Consultar links gerados</a>
</section>
@endsection

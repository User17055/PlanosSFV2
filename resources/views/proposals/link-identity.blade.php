@extends('layouts.public')
@section('title','Assinar contrato')
@section('inline_errors','1')
@section('content')
<div class="auth-layout">
<section class="auth-card">
<img class="auth-logo" src="{{ asset('assets/brand/sao-francisco-logo.png') }}" alt="São Francisco — serviços veterinários" width="178" height="127">
<h1>Seu contrato está pronto</h1>
<p>Você não precisa de senha para {{ ($signed ?? false) ? 'pagar a entrada' : 'assinar' }}. Para proteger seus dados, confirme o CPF do titular.</p>
<form method="POST" action="{{ route('sales.proposal.confirm',$token) }}">@csrf<label class="field">CPF do titular<input type="text" name="document" value="{{ old('document') }}" required inputmode="numeric" autocomplete="off" maxlength="20" placeholder="000.000.000-00" autofocus @error('document') aria-invalid="true" @enderror>@error('document')<small class="field-error" role="alert">{{ $message }}</small>@enderror</label><button class="button button-primary full-width" data-submit-label="Abrindo...">Abrir o contrato</button></form>
</section>
<aside class="auth-visual"><div class="auth-copy"><span class="auth-eyebrow">Bem-vindo à São Francisco</span><h2>Aqui, o cuidado<br>tem um lugar<span>.</span></h2><p>Planos e pacotes para acompanhar seu melhor amigo em cada fase da vida.</p></div><img src="{{ asset('assets/brand/login-vet.webp') }}" alt="" width="800" height="800"></aside>
</div>
@endsection

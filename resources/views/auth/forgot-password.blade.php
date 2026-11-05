@extends('layouts.public')
@section('title','Criar ou recuperar senha')
@section('inline_errors','1')
@section('content')
<div class="auth-layout">
<section class="auth-card">
<img class="auth-logo" src="{{ asset('assets/brand/sao-francisco-logo.png') }}" alt="São Francisco — serviços veterinários" width="178" height="127">
<h1>Criar ou recuperar senha</h1>
<p>Informe o e-mail do seu cadastro. Enviamos um link para você criar uma nova senha.</p>
@if(session('status'))<div class="auth-note" role="status">{{ session('status') }}</div>@endif
<form method="POST" action="{{ route('password.email') }}">@csrf<label class="field">E-mail<input type="email" name="email" value="{{ old('email') }}" required maxlength="180" autocomplete="email" placeholder="Digite seu e-mail" autofocus @error('email') aria-invalid="true" @enderror>@error('email')<small class="field-error" role="alert">{{ $message }}</small>@enderror</label><button class="button button-primary full-width" data-submit-label="Enviando...">Enviar link</button></form>
<div class="auth-register"><a href="{{ route('login') }}">Voltar para entrar</a></div>
</section>
<aside class="auth-visual"><div class="auth-copy"><span class="auth-eyebrow">Bem-vindo à São Francisco</span><h2>Aqui, o cuidado<br>tem um lugar<span>.</span></h2><p>Planos e pacotes para acompanhar seu melhor amigo em cada fase da vida.</p></div><img src="{{ asset('assets/brand/login-vet.webp') }}" alt="" width="800" height="800"></aside>
</div>
@endsection

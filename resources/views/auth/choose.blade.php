@extends('layouts.public')
@section('title','Como você quer entrar?')
@section('inline_errors','1')
@section('content')
<div class="auth-layout">
<section class="auth-card">
<img class="auth-logo" src="{{ asset('assets/brand/sao-francisco-logo.png') }}" alt="São Francisco — serviços veterinários" width="178" height="127">
<h1>Como você quer entrar?</h1>
<p>Olá, {{ \Illuminate\Support\Str::title(mb_strtolower(strtok(auth()->user()->name,' '))) }}. Escolha onde quer continuar.</p>
<form method="POST" action="{{ route('account.admin') }}">@csrf<button class="button button-primary full-width">Painel da administração</button></form>
<h2 class="auth-subtitle">Minha área de cliente</h2>
@if($linked)
<form method="POST" action="{{ route('account.customer') }}">@csrf<button class="button button-secondary full-width">Entrar como cliente</button></form>
@else
<p class="auth-note">Na primeira vez, confirme sua conta de cliente com o e-mail ou CPF e a senha dela. Depois, é só escolher aqui.</p>
<form method="POST" action="{{ route('account.customer') }}">@csrf<label class="field">E-mail ou CPF da conta de cliente<input type="text" name="login" value="{{ old('login') }}" required autocomplete="off" @error('login') aria-invalid="true" @enderror>@error('login')<small class="field-error" role="alert">{{ $message }}</small>@enderror</label><label class="field">Senha da conta de cliente<div class="password-field"><input type="password" name="password" required autocomplete="off" id="link-password" @error('password') aria-invalid="true" @enderror><button class="text-button" type="button" data-toggle-password="link-password" aria-label="Mostrar senha">Mostrar</button></div>@error('password')<small class="field-error" role="alert">{{ $message }}</small>@enderror</label><button class="button button-secondary full-width" data-submit-label="Entrando...">Entrar como cliente</button></form>
@endif
<form method="POST" action="{{ route('logout') }}" class="auth-register">@csrf<button class="text-button">Sair</button></form>
</section>
<aside class="auth-visual"><div class="auth-copy"><span class="auth-eyebrow">Bem-vindo à São Francisco</span><h2>Aqui, o cuidado<br>tem um lugar<span>.</span></h2><p>Planos e pacotes para acompanhar seu melhor amigo em cada fase da vida.</p></div><img src="{{ asset('assets/brand/login-vet.webp') }}" alt="" width="800" height="800"></aside>
</div>
@endsection

@extends('layouts.public')
@section('title','Acesse seu espaço')
@section('inline_errors','1')
@section('content')
<div class="auth-layout">
<section class="auth-card">
<img class="auth-logo" src="{{ asset('assets/brand/sao-francisco-logo.png') }}" alt="São Francisco — serviços veterinários" width="178" height="127">
<h1>Acesse seu espaço</h1>
<p>Planos, pets e contratos em um só lugar.</p>
@if(request('cadastro')==='existente')<div class="auth-note" role="status">Você já tem cadastro. Entre com seu e-mail ou CPF e sua senha.</div>@endif
<form method="POST" action="{{ route('authenticate') }}">@csrf<label class="field">E-mail ou CPF<input type="text" name="email" value="{{ old('email', mb_substr((string) request('login'), 0, 255)) }}" required autocomplete="username" placeholder="Digite seu e-mail ou CPF" @if(! request('login')) autofocus @endif @error('email') aria-invalid="true" @enderror>@error('email')<small class="field-error" role="alert">{{ $message }}</small>@enderror</label><label class="field">Senha<div class="password-field"><input type="password" name="password" required autocomplete="current-password" placeholder="Digite sua senha" id="login-password" @if(request('login')) autofocus @endif @error('password') aria-invalid="true" @enderror><button class="text-button" type="button" data-toggle-password="login-password" aria-label="Mostrar senha">Mostrar</button></div>@error('password')<small class="field-error" role="alert">{{ $message }}</small>@enderror</label><label class="check-label"><input type="checkbox" name="remember" value="1"><span>Continuar conectado neste dispositivo</span></label><button class="button button-primary full-width" data-submit-label="Entrando...">Entrar</button></form>
@if(Route::has('register'))<div class="auth-register">Ainda não tem conta? <a href="{{ route('register',['token'=>$inviteToken ?? null]) }}">Crie uma agora.</a></div>@endif
</section>
<aside class="auth-visual"><div class="auth-copy"><span class="auth-eyebrow">Bem-vindo à São Francisco</span><h2>Aqui, o cuidado<br>tem um lugar<span>.</span></h2><p>Planos e pacotes para acompanhar seu melhor amigo em cada fase da vida.</p></div><img src="{{ asset('assets/brand/login-vet.webp') }}" alt="" width="800" height="800"></aside>
</div>
@endsection

@extends('layouts.public')
@section('title','Criar nova senha')
@section('inline_errors','1')
@section('content')
<div class="auth-layout">
<section class="auth-card">
<img class="auth-logo" src="{{ asset('assets/brand/sao-francisco-logo.png') }}" alt="São Francisco — serviços veterinários" width="178" height="127">
<h1>Criar nova senha</h1>
<p>Use pelo menos 12 caracteres. Depois, entre com seu e-mail e a nova senha.</p>
<form method="POST" action="{{ route('password.update') }}">@csrf<input type="hidden" name="token" value="{{ $token }}"><label class="field">E-mail<input type="email" name="email" value="{{ old('email',$email) }}" required maxlength="180" autocomplete="username" @error('email') aria-invalid="true" @enderror>@error('email')<small class="field-error" role="alert">{{ $message }}</small>@enderror</label><label class="field">Nova senha<div class="password-field"><input type="password" name="password" required minlength="12" autocomplete="new-password" id="reset-password" autofocus @error('password') aria-invalid="true" @enderror><button class="text-button" type="button" data-toggle-password="reset-password" aria-label="Mostrar senha">Mostrar</button></div>@error('password')<small class="field-error" role="alert">{{ $message }}</small>@enderror</label><label class="field">Confirme a nova senha<input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label><button class="button button-primary full-width" data-submit-label="Salvando...">Salvar nova senha</button></form>
<div class="auth-register"><a href="{{ route('password.request') }}">Pedir um novo link</a></div>
</section>
<aside class="auth-visual"><div class="auth-copy"><span class="auth-eyebrow">Bem-vindo à São Francisco</span><h2>Aqui, o cuidado<br>tem um lugar<span>.</span></h2><p>Planos e pacotes para acompanhar seu melhor amigo em cada fase da vida.</p></div><img src="{{ asset('assets/brand/login-vet.webp') }}" alt="" width="800" height="800"></aside>
</div>
@endsection

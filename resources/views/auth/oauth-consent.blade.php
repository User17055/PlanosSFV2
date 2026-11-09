@extends('layouts.public')
@section('title','Entrar na Tudo pra Pet')
@section('content')
<div class="auth-layout">
<section class="auth-card">
<img class="auth-logo" src="{{ asset('assets/brand/sao-francisco-logo.png') }}" alt="São Francisco — serviços veterinários" width="178" height="127">
<h1>Entrar na Tudo pra Pet</h1>
<p>A Tudo pra Pet quer usar a sua conta São Francisco para você entrar na loja, sem criar outra senha.</p>
<div class="auth-note">Ela vai receber: nome, e-mail, CPF, telefone e se você tem plano ativo. Sua senha não é compartilhada.</div>
<form method="POST" action="{{ route('oauth.approve') }}">@csrf
@foreach($data as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
<button class="button button-primary full-width" name="decision" value="allow">Permitir e continuar</button>
<button class="text-button auth-register" name="decision" value="deny" formnovalidate>Cancelar</button>
</form>
</section>
<aside class="auth-visual"><div class="auth-copy"><span class="auth-eyebrow">Bem-vindo à São Francisco</span><h2>Um login<br>para cuidar<span>.</span></h2><p>Com o seu plano São Francisco, você entra na loja da Tudo pra Pet com a mesma conta.</p></div><img src="{{ asset('assets/brand/login-vet.webp') }}" alt="" width="800" height="800"></aside>
</div>
@endsection

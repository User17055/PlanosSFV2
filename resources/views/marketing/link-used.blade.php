@extends('layouts.public')
@section('title','Link já usado')
@section('content')
<div class="auth-layout">
<section class="auth-card">
<img class="auth-logo" src="{{ asset('assets/brand/sao-francisco-logo.png') }}" alt="São Francisco — serviços veterinários" width="178" height="127">
<h1>Este link já foi usado</h1>
<p>Cada link de cadastro serve para uma pessoa. Se foi você quem se cadastrou por ele, entre na sua conta para acompanhar o plano. Se não, peça um novo link para a equipe da São Francisco.</p>
<a class="button button-primary full-width" href="{{ route('login') }}">Entrar na minha conta</a>
</section>
<aside class="auth-visual"><div class="auth-copy"><span class="auth-eyebrow">Bem-vindo à São Francisco</span><h2>Aqui, o cuidado<br>tem um lugar<span>.</span></h2><p>Planos e pacotes para acompanhar seu melhor amigo em cada fase da vida.</p></div><img src="{{ asset('assets/brand/login-vet.webp') }}" alt="" width="800" height="800"></aside>
</div>
@endsection

@extends('layouts.public')
@section('title','Confirme seu contato')
@section('content')
<div class="public-container"><section class="card form-card" style="max-width:580px;margin:60px auto"><div class="eyebrow">SEU ACESSO</div><h1>Confirme seu e-mail</h1><p>Enviamos um link para o endereço cadastrado. Abra a mensagem para verificar o contato e acessar seus pets, propostas e contratos.</p>@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif<form method="POST" action="{{ route('verification.resend') }}">@csrf<button class="button button-primary">Enviar outro link de verificação</button></form><p class="fine-print">Se o endereço estiver incorreto, solicite a revisão do cadastro à unidade responsável.</p><form method="POST" action="{{ route('logout') }}">@csrf<button class="text-button">Sair</button></form></section></div>
@endsection

@extends('layouts.app')
@section('title','Pets para escolher')
@section('content')
@php
$money=fn($cents)=>'R$ '.number_format($cents/100,2,',','.');
$species=['dog'=>'cão','cat'=>'gato'];
$reasons=['escolher o pet'=>'Pet não identificado','pet em óbito no SimplesVet'=>'O pet indicado está em óbito no SimplesVet','achar o cliente'=>'Cliente sem cadastro no SimplesVet'];
$cpf=fn($v)=>$v&&strlen($v)===11?substr($v,0,3).'.'.substr($v,3,3).'.'.substr($v,6,3).'-'.substr($v,9):'sem CPF';
@endphp
<div class="page-heading"><div><h1>Pets para escolher</h1><p>Planos da base antiga que estão ativos na Vindi, mas ainda sem pet ligado aqui. Escolha o pet de cada um: o contrato nasce ativo, ligado à cobrança da Vindi, sem comissão e sem e-mail ao cliente.</p></div><div class="analytics-heading-actions"><a class="button button-secondary" href="{{ route('dashboard') }}">Voltar ao painel</a></div></div>
<p class="dash-simple-line"><strong>{{ number_format($total,0,',','.') }}</strong> para escolher · <strong>{{ number_format($done,0,',','.') }}</strong> já resolvido(s)</p>
@forelse($groups as $items)
@php $customer=$items->first()->customer; @endphp
<section class="card legacy-pending">
<div class="legacy-pending-head"><div><h2><a href="{{ route('customers.show',$customer) }}">{{ $customer->name }}</a></h2><small>CPF {{ $cpf($customer->document) }} · {{ $customer->pets->count() }} pet(s) na ficha</small></div><a class="text-link" href="{{ route('customers.show',$customer) }}">Abrir a ficha</a></div>
@foreach($items as $item)
<div class="legacy-pending-item">
<div><strong>{{ $item->productVersion->name }} · {{ $money($item->price_cents) }} por mês</strong><small>Na Vindi desde {{ $item->started_at?->format('d/m/Y') ?? 'data não informada' }} · {{ $reasons[$item->reason] ?? ucfirst($item->reason) }}@if($item->hint) · {{ $item->hint }}@endif</small></div>
@if($customer->pets->isEmpty())
<p class="dash-simple-note">Este cliente não tem pet na ficha. Cadastre o pet na ficha e volte aqui.</p>
@else
<form method="POST" action="{{ route('legacy.pending.link',$item) }}" class="legacy-pending-form">@csrf<label class="field">Qual pet?<select name="pet_id" required><option value="">Escolha o pet</option>@foreach($customer->pets as $pet)<option value="{{ $pet->id }}">{{ $pet->name }} · {{ $species[$pet->species] ?? $pet->species }}{{ $pet->breed ? ' · '.$pet->breed : '' }}</option>@endforeach</select></label><button class="button button-primary">Ligar ao pet</button></form>
@endif
<details class="legacy-pending-dismiss"><summary>Não vai virar contrato</summary><form method="POST" action="{{ route('legacy.pending.dismiss',$item) }}" class="legacy-pending-form">@csrf<label class="field">Motivo<input name="reason" required minlength="5" maxlength="500" placeholder="Ex.: pet faleceu, cobrança não é de pet"></label><button class="button button-secondary">Tirar da lista</button></form></details>
</div>
@endforeach
</section>
@empty
<section class="card legacy-pending"><div class="analytics-empty">Nenhum pet para escolher. Todos os planos da base antiga estão ligados a um pet.</div></section>
@endforelse
@endsection

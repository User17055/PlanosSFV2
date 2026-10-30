@extends('layouts.app')
@section('title',auth()->user()->role==='customer'?'Meus dados':'Atualizar cadastro')
@section('content')
@php
$isCustomer=auth()->user()->role==='customer';
$returnUrl=auth()->user()->role==='customer'?route('portal'):route('customers.show',$customer);
$currentAddress=$formattedAddress ?? (is_array($customer->address)?collect($customer->address)->filter(fn($value)=>is_scalar($value))->join(', '):(string)$customer->address);
@endphp
@unless($isCustomer)<a class="back-link" href="{{ $returnUrl }}">← Voltar ao perfil</a>@endunless
<div class="page-heading"><div><h1>{{ auth()->user()->role==='customer'?'Meus dados':'Dados do tutor' }}</h1><p>{{ auth()->user()->role==='customer'?'Mantenha seus dados de contato atualizados.':'Atualize as informações de contato de '.$customer->name.'.' }}</p></div></div>
<form class="form-layout {{ $isCustomer?'form-layout-focused':'' }}" method="POST" action="{{ route('customers.update',$customer) }}">
@csrf
<section class="card form-card">
@unless($isCustomer)<div class="form-section-heading"><span class="quick-icon">@include('components.icon',['name'=>'users'])</span><div><h2>Cadastro do tutor</h2><p>As alterações são registradas com autor, data e motivo.</p></div></div>@endunless
<div class="form-grid">
<label class="field span-2">Nome completo <span>*</span><input name="name" value="{{ old('name',$customer->name) }}" required maxlength="180" autocomplete="name"></label>
<label class="field">Telefone <span>*</span><input name="phone" value="{{ old('phone',$customer->phone) }}" required maxlength="30" autocomplete="tel"></label>
<div class="field">E-mail cadastrado<input readonly value="{{ $customer->email }}" aria-readonly="true"><small>{{ $isCustomer?'Para trocar o e-mail, fale com a sua unidade.':'A identificação e o e-mail não são alterados neste formulário.' }}</small></div>

@include('customers.partials.contract-fields',['contractCustomer'=>$customer])
@if($isCustomer)<input type="hidden" name="reason" value="Atualização feita pelo próprio tutor no portal.">
@else<div class="span-2">@include('components.customer-acquisition',['acquisitionCustomer'=>$customer])</div>
<label class="field span-2">Motivo da atualização <span>*</span><textarea name="reason" required minlength="5" maxlength="1000" rows="3" placeholder="Ex.: atualização do telefone e endereço de contato">{{ old('reason') }}</textarea></label>@endif
</div>
@if($isCustomer)<p class="fine-print">Os contratos já assinados continuam com os dados da época da assinatura.</p>@else<div class="alert alert-info compact-alert">@include('components.icon',['name'=>'lock'])<div>Contratos já assinados não mudam.</div></div>@endif
<div class="form-actions"><a class="button button-secondary" href="{{ $returnUrl }}">Voltar</a><button class="button button-primary">@include('components.icon',['name'=>'check']) {{ $isCustomer?'Salvar':'Salvar atualização' }}</button></div>
</section>
@unless($isCustomer)<aside><div class="info-card"><span class="info-card-icon">@include('components.icon',['name'=>'heart'])</span><h3>Manter contato também é cuidar.</h3><p>Dados corretos ajudam a acompanhar sua jornada e a localizar o mesmo cadastro em toda a rede.</p><hr><p>O histórico contratual e financeiro permanece vinculado ao titular original.</p></div></aside>@endunless
</form>
@endsection

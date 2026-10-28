@extends('layouts.app')
@section('title','Atualizar pet')
@section('content')
@php $isCustomer=auth()->user()->role==='customer'; $returnUrl=$isCustomer?route('portal'):route('customers.show',$pet->customer_id); @endphp
@unless($isCustomer)<a class="back-link" href="{{ $returnUrl }}">← Voltar ao perfil</a>@endunless
<div class="page-heading"><div><h1>Dados de {{ $pet->name }}</h1></div></div>
<form class="form-layout form-layout-focused" method="POST" action="{{ route('pets.update',$pet) }}">
@csrf
<section class="card form-card">
@unless($isCustomer)<div class="form-section-heading"><div><h2>Dados do pet</h2><p>{{ ['dog'=>'Cão','cat'=>'Gato'][$pet->species] ?? $pet->species }} · Tutor: {{ data_get($pet,'customer.name','—') }}</p></div></div>@endunless
<div class="form-grid">
<label class="field">Nome <span>*</span><input name="name" required maxlength="120" value="{{ old('name',$pet->name) }}"></label>
<label class="field">Raça<input name="breed" maxlength="120" value="{{ old('breed',$pet->breed) }}" placeholder="Raça ou sem raça definida"></label>
<label class="field">Cor / pelagem<input name="color" maxlength="100" value="{{ old('color',$pet->color) }}"></label>
<label class="field">Sexo<select name="sex"><option value="unknown" @selected(old('sex',$pet->sex)==='unknown')>Não informado</option><option value="male" @selected(old('sex',$pet->sex)==='male')>Macho</option><option value="female" @selected(old('sex',$pet->sex)==='female')>Fêmea</option></select></label>
<label class="field">Nascimento<input type="date" name="birth_date" value="{{ old('birth_date',$pet->birth_date?->format('Y-m-d')) }}" max="{{ now('America/Sao_Paulo')->format('Y-m-d') }}"></label>
@if($isCustomer)<input type="hidden" name="reason" value="Atualização feita pelo próprio tutor no portal.">@else<label class="field span-2">Motivo da atualização <span>*</span><textarea name="reason" required minlength="5" maxlength="1000" rows="3" placeholder="Descreva a correção ou atualização realizada">{{ old('reason') }}</textarea></label>@endif
</div>
@unless($isCustomer)<div class="alert alert-info compact-alert">@include('components.icon',['name'=>'lock'])<div>Os dados aceitos nos contratos anteriores serão preservados. A atualização cadastral é auditada e não transfere benefícios ou troca o pet coberto.</div></div>@endunless
<div class="form-actions"><a class="button button-secondary" href="{{ $returnUrl }}">Voltar</a><button class="button button-primary">@include('components.icon',['name'=>'check']) {{ $isCustomer?'Salvar':'Salvar atualização' }}</button></div>
</section>

</form>
@endsection

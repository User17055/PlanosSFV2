@extends('layouts.app')
@section('title','Novo tutor e pet')
@section('content')
<a href="{{ route('customers.index') }}" class="back-link">← Cadastros</a>
<div class="page-heading"><div><h1>Novo tutor e pet</h1><p>Preencha os dados do tutor e do pet. Os campos com * são obrigatórios.</p></div></div>
<p class="fine-print">O tutor já está cadastrado? <a class="text-link" href="{{ route('customers.index') }}">Localize a ficha existente</a> para adicionar outro pet.</p>
<form method="POST" action="{{ route('customers.store') }}" class="workspace-stack">
    @csrf
    <input type="hidden" name="_workspace_form" value="customer-create">
    @include('customers.partials.errors',['bag'=>'customerCreate'])
    <section class="card workspace-section form-card">
        <div class="form-section-heading"><span class="step-number">1</span><div><h2>Tutor</h2><p>Identificação e contato de quem é responsável pelo pet.</p></div></div>
        <div class="workspace-fields">
            <label class="field">Nome completo <span>*</span><input name="name" required minlength="3" maxlength="180" autocomplete="name" value="{{ old('name') }}" placeholder="Nome e sobrenome"></label>
            <label class="field">CPF <span>*</span><input name="document" required value="{{ old('document') }}" inputmode="numeric" data-mask="cpf" maxlength="14" placeholder="000.000.000-00"></label>
            <label class="field">E-mail <span>*</span><input name="email" required type="email" maxlength="180" autocomplete="email" value="{{ old('email') }}" placeholder="tutor@exemplo.com"></label>
            <label class="field">Telefone <span>*</span><input name="phone" required maxlength="30" autocomplete="tel" value="{{ old('phone') }}" placeholder="DDD e número"></label>
            
@include('customers.partials.contract-fields',['contractCustomer'=>null,'contractBag'=>'customerCreate'])
<div class="span-2">@include('components.customer-acquisition',['acquisitionCustomer'=>null,'acquisitionBag'=>'customerCreate'])</div>
            @if($franchises->count()===1)<input type="hidden" name="originating_franchise_id" value="{{ $franchises->first()->id }}">@else<label class="field">Unidade do cadastro <span>*</span><select name="originating_franchise_id" required>
                <option value="">Selecione a unidade</option>
                @foreach($franchises as $franchise)<option value="{{ $franchise->id }}" @selected((string)old('originating_franchise_id',auth()->user()->franchise_id ?? ($franchises->count()===1?$franchises->first()->id:null))===(string)$franchise->id)>{{ $franchise->name }}</option>@endforeach
            </select></label>@endif
        </div>
    </section>
    <section class="card workspace-section form-card">
        <div class="form-section-heading"><span class="step-number">2</span><div><h2>Primeiro pet</h2><p>Ele ficará vinculado ao tutor informado acima. Você poderá adicionar os demais na mesma ficha.</p></div></div>
        @include('customers.partials.pet-fields',['prefix'=>'pet_','bag'=>'customerCreate','pet'=>null,'editing'=>false])
    </section>
    <div class="form-actions"><a class="button button-secondary" href="{{ route('customers.index') }}">Voltar</a><button class="button button-primary">@include('components.icon',['name'=>'check']) Salvar e abrir ficha</button></div>
</form>
@endsection

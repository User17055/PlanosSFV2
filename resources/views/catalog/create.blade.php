@extends('layouts.app')
@section('title',isset($version)?'Editar rascunho':'Novo produto')
@section('content')
@php
$editing=isset($version);
$benefits=old('benefits',$editing ? $version->benefitRules->map(fn($rule)=>$rule->only(['code','name','waiting_days','quantity_limit','period_type']))->toArray() : [['code'=>'','name'=>'','waiting_days'=>0,'quantity_limit'=>'','period_type'=>'contract_year']]);
$selectedSpecies=old('species',$editing?$version->species:['dog','cat']);
@endphp
<a class="back-link" href="{{ route('catalog.index') }}">← Planos e pacotes</a>
<div class="page-heading"><div><h1>{{ $editing?'Editar plano ou pacote':'Novo plano ou pacote' }}</h1><p>Preencha os dados, o preço e as coberturas abaixo.</p></div></div>
<form method="POST" action="{{ $editing?route('catalog.update',$version):route('catalog.store') }}" class="form-layout">
@csrf
<section class="card form-card">
<div class="form-section-heading"><span class="step-number">01</span><div><h2>Produto e condições comerciais</h2><p>A categoria do produto permanece fixa em todas as versões.</p></div></div>
<div class="form-grid">
<label class="field">Nome do produto <span>*</span><input name="name" required value="{{ old('name',$editing?$version->product->name:'') }}" @readonly($editing) placeholder="Nome permanente do produto"></label>
<label class="field">Código comercial <span>*</span><input name="code" required value="{{ old('code',$editing?$version->product->code:'') }}" @readonly($editing) placeholder="Ex.: SF-ESSENCIAL"></label>
<label class="field">Categoria <span>*</span><select name="category" @disabled($editing)><option value="health_plan" @selected(old('category',$editing?$version->product->category:'health_plan')==='health_plan')>Plano de Saúde</option><option value="package" @selected(old('category',$editing?$version->product->category:'')==='package')>Pacote</option></select></label>
<label class="field">Nome desta versão <span>*</span><input name="version_name" required value="{{ old('version_name',$editing?$version->name:'') }}" placeholder="Nome exibido nas contratações"></label>
<label class="field">Preço em reais <span>*</span><input name="price" required inputmode="decimal" value="{{ old('price',$editing?number_format($version->price_cents/100,2,',',''):'') }}" placeholder="0,00"></label>
<label class="field">Forma de cobrança <span>*</span><select name="billing_cycle" required><option value="monthly" @selected(old('billing_cycle',$editing?$version->billing_cycle:'monthly')==='monthly')>Recorrência mensal</option><option value="annual" @selected(old('billing_cycle',$editing?$version->billing_cycle:'')==='annual')>Recorrência anual</option><option value="one_time" @selected(old('billing_cycle',$editing?$version->billing_cycle:'')==='one_time')>Pagamento único</option></select></label>
<label class="field">Duração em meses <span>*</span><input type="number" name="duration_months" required min="1" max="120" value="{{ old('duration_months',$editing?$version->duration_months:12) }}"></label>
<div class="field">Espécies elegíveis <span>*</span><div class="inline-options" style="padding-top:6px"><label><input type="checkbox" name="species[]" value="dog" @checked(in_array('dog',$selectedSpecies))> Cães</label><label><input type="checkbox" name="species[]" value="cat" @checked(in_array('cat',$selectedSpecies))> Gatos</label></div></div>
</div>
<div class="form-divider"></div>
<div class="form-section-heading"><span class="step-number">02</span><div><h2>Benefícios e renovação</h2><p>Defina o limite e o período de apuração de cada benefício.</p></div></div>
<div data-benefit-rows>
@foreach($benefits as $index=>$benefit)
<div class="benefit-editor" data-benefit-row>
<label class="field">Código <span>*</span><input name="benefits[{{ $index }}][code]" required value="{{ data_get($benefit,'code') }}" placeholder="CONSULTA"></label>
<label class="field">Benefício <span>*</span><input name="benefits[{{ $index }}][name]" required value="{{ data_get($benefit,'name') }}" placeholder="Descrição do serviço"></label>
<label class="field">Carência (dias)<input name="benefits[{{ $index }}][waiting_days]" type="number" min="0" max="3650" required value="{{ data_get($benefit,'waiting_days',0) }}"></label>
<label class="field">Limite<input name="benefits[{{ $index }}][quantity_limit]" type="number" min="1" value="{{ data_get($benefit,'quantity_limit') }}" placeholder="Ilimitado"></label>
<label class="field">Período <span>*</span><select name="benefits[{{ $index }}][period_type]" required>
@foreach(['calendar_month'=>'Mês-calendário','monthly_cycle'=>'Ciclo mensal','contract_year'=>'Ano contratual','lifetime'=>'Toda a vigência'] as $key=>$label)
<option value="{{ $key }}" @selected(data_get($benefit,'period_type')===$key)>{{ $label }}</option>
@endforeach
</select></label>
<button class="icon-button" type="button" data-remove-benefit aria-label="Remover benefício">@include('components.icon',['name'=>'close'])</button>
</div>
@endforeach
</div>
<button class="button button-secondary button-small section-gap" type="button" data-add-benefit>@include('components.icon',['name'=>'plus']) Adicionar benefício</button>
<div class="form-divider"></div>
<div class="form-section-heading"><span class="step-number">03</span><div><h2>Condições contratuais</h2><p>Inclua o texto integral aprovado antes de publicar.</p></div></div>
<label class="field">Texto integral do contrato <span>*</span><textarea name="contract_template" required rows="12" placeholder="Texto do contrato, regras de vigência, cobrança, carências, cancelamento, exclusões e condições aprovadas...">{{ old('contract_template',$editing?$version->contract_template:'') }}</textarea></label>
<details class="contract-preview"><summary>Campos preenchidos automaticamente</summary><p>Use os marcadores abaixo onde a informação deve aparecer. Repita o mesmo marcador para repetir a informação, inclusive sob a linha de assinatura. Na revisão da proposta e no PDF, os campos recebem os dados daquele contrato.</p><div class="contract-text">@foreach(array_keys(app(\App\Services\Contracts\ContractTemplate::class)->values([])) as $field)<code>&#123;&#123;{{ $field }}&#125;&#125;</code> @endforeach</div><p>A assinatura é coletada pelo fluxo digital, tablet ou papel. Um marcador de nome apenas identifica quem assina. Dados opcionais ausentes aparecem como “Não informado”.</p></details>
<div class="form-actions"><a href="{{ route('catalog.index') }}" class="button button-secondary">Cancelar</a><button class="button button-primary">@include('components.icon',['name'=>'check']) Salvar rascunho</button></div>
</section>
<aside><div class="info-card"><span class="info-card-icon">@include('components.icon',['name'=>'lock'])</span><h3>Uma versão, uma promessa.</h3><p>Enquanto estiver em rascunho, você pode revisar as condições. A publicação congela preço, cobertura, carências, limites e contrato.</p><hr><p>Novas versões valem para novas contratações. Os contratos existentes continuam vinculados às condições aceitas.</p></div></aside>
</form>
<template id="benefit-row-template"><div class="benefit-editor" data-benefit-row><label class="field">Código <span>*</span><input name="benefits[__INDEX__][code]" required placeholder="CODIGO"></label><label class="field">Benefício <span>*</span><input name="benefits[__INDEX__][name]" required placeholder="Nome do benefício"></label><label class="field">Carência (dias)<input name="benefits[__INDEX__][waiting_days]" type="number" min="0" max="3650" required value="0"></label><label class="field">Limite<input name="benefits[__INDEX__][quantity_limit]" type="number" min="1" placeholder="Ilimitado"></label><label class="field">Período <span>*</span><select name="benefits[__INDEX__][period_type]" required><option value="calendar_month">Mês-calendário</option><option value="monthly_cycle">Ciclo mensal</option><option value="contract_year">Ano contratual</option><option value="lifetime">Toda a vigência</option></select></label><button type="button" class="icon-button" data-remove-benefit aria-label="Remover benefício">@include('components.icon',['name'=>'close'])</button></div></template>
@endsection

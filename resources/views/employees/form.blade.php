@extends('layouts.app')
@section('title',$employee->exists?'Editar funcionário':'Cadastrar funcionário')
@section('content')
<a class="back-link" href="{{ route('employees.index') }}">← Funcionários</a>
<div class="page-heading"><div><h1>{{ $employee->exists?'Editar funcionário':'Novo funcionário' }}</h1></div></div>
<form method="POST" action="{{ $employee->exists?route('employees.update',$employee):route('employees.store') }}" class="form-layout">@csrf
<section class="card form-card"><h2>Dados do funcionário</h2><div class="form-grid">
<label class="field span-2">Nome completo <span>*</span><input name="name" required maxlength="180" value="{{ old('name',$employee->name) }}"></label>
<label class="field">Matrícula<input name="employee_code" maxlength="40" value="{{ old('employee_code',$employee->employee_code) }}"></label>
<label class="field">Cargo<input name="job_title" maxlength="100" value="{{ old('job_title',$employee->job_title) }}"></label>
<label class="field">Telefone<input name="phone" maxlength="30" value="{{ old('phone',$employee->phone) }}"></label>
<label class="field">Data de admissão<input type="date" name="hired_at" max="{{ now('America/Sao_Paulo')->toDateString() }}" value="{{ old('hired_at',$employee->hired_at?->toDateString()) }}"></label>
<label class="field span-2">E-mail de acesso <span>*</span><input type="email" name="email" required maxlength="180" value="{{ old('email',$employee->email) }}"></label>
<label class="field">{{ $employee->exists?'Nova senha (opcional)':'Senha inicial *' }}<input type="password" name="password" minlength="12" @required(!$employee->exists) autocomplete="new-password"><small>Mínimo de 12 caracteres.</small></label>
<label class="field">Confirmar senha<input type="password" name="password_confirmation" minlength="12" @required(!$employee->exists) autocomplete="new-password"></label>
<label class="field">Perfil de acesso <span>*</span><select name="role" required>@foreach($roles as $key=>$label)<option value="{{ $key }}" @selected(old('role',$employee->role)===$key)>{{ $label }}</option>@endforeach</select></label>
<label class="field">Situação<select name="active"><option value="1" @selected(old('active',$employee->active)==1)>Ativo</option><option value="0" @selected(old('active',$employee->active)==0)>Desativado</option></select></label>
<label class="field span-2">Meta mensal de vendas (R$)<input type="number" name="monthly_sales_target" min="0" max="10000000" step="0.01" value="{{ old('monthly_sales_target',number_format($employee->monthly_sales_target_cents/100,2,'.','')) }}"><small>Meta sobre a soma das parcelas das novas contratações de planos no mês.</small></label>
</div><fieldset class="section-gap"><legend>Unidades autorizadas <span>*</span></legend>@foreach($franchises as $franchise)<label class="check-label"><input type="checkbox" name="franchise_ids[]" value="{{ $franchise->id }}" @checked(in_array($franchise->id,old('franchise_ids',$employee->exists?$employee->franchises->pluck('id')->all():[])))><span>{{ $franchise->name }}</span></label>@endforeach</fieldset><div class="form-actions"><a class="button button-secondary" href="{{ route('employees.index') }}">Voltar</a><button class="button button-primary">Salvar funcionário</button></div></section>
<aside><div class="info-card"><h3>Vendas e comissões no mesmo cadastro.</h3><p>O perfil de vendas acompanha suas próprias comissões. Gestores e financeiro consultam as unidades autorizadas. A administração central gerencia a equipe.</p><hr><p>As comissões seguem as regras de cada plano e são liberadas pelo primeiro pagamento confirmado.</p><a class="text-link" href="{{ route('commissions.index') }}">Consultar regras e comissões →</a></div></aside>
</form>
@endsection

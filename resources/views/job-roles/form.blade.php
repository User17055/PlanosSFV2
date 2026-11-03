@extends('layouts.app')
@section('title',$jobRole->exists?'Editar cargo':'Novo cargo')
@section('content')
@php
$topics=collect(\App\Models\JobRole::OPTIONS)->map(fn($option,$key)=>['key'=>$key,'label'=>$option[0],'roles'=>\App\Models\JobRole::rolesOf($key)])->groupBy(fn($option)=>explode('.',$option['key'])[0]);
$checked=old('permissions',$jobRole->permissions ?? []);
@endphp
<a class="back-link" href="{{ route('employees.roles.index') }}">← Cargos e permissões</a>
<div class="page-heading"><div><h1>{{ $jobRole->exists?'Editar cargo':'Novo cargo' }}</h1></div></div>
<form method="POST" action="{{ $jobRole->exists?route('employees.roles.update',$jobRole):route('employees.roles.store') }}" class="form-layout">@csrf
<section class="card form-card"><h2>Dados do cargo</h2><div class="form-grid">
<label class="field">Nome do cargo <span>*</span><input name="name" required minlength="2" maxlength="80" placeholder="Ex.: Recepção" value="{{ old('name',$jobRole->name) }}"></label>
<label class="field">Nível de acesso <span>*</span><select name="base_role" required data-permission-level>@foreach($levels as $key=>$label)<option value="{{ $key }}" @selected(old('base_role',$jobRole->base_role)===$key)>{{ $label }}</option>@endforeach</select><small>É o limite do cargo: define as opções abaixo.</small></label>
</div>
<fieldset class="section-gap"><legend>O que este cargo pode ver e fazer</legend><small class="area-hint">O que ficar desmarcado some do menu e é bloqueado no sistema.</small>@error('permissions')<small class="field-error area-hint" role="alert">{{ $message }}</small>@enderror
<div class="permission-topics">
@foreach($topics as $topic=>$options)
<div class="permission-topic" data-permission-topic><strong>{{ \App\Models\User::AREAS[$topic][0] }}</strong>
@foreach($options as $option)<label class="check-label" data-roles="{{ implode(' ',$option['roles']) }}"><input type="checkbox" name="permissions[]" value="{{ $option['key'] }}" @checked(in_array($option['key'],$checked)) @if($loop->first) data-permission-view @endif><span>{{ $option['label'] }}</span></label>@endforeach
</div>
@endforeach
</div></fieldset>
<div class="form-actions"><a class="button button-secondary" href="{{ route('employees.roles.index') }}">Voltar</a><button class="button button-primary">Salvar cargo</button></div></section>
<aside><div class="info-card"><h3>Como funciona o nível de acesso</h3><p>O cargo só pode restringir o nível escolhido, nunca ir além dele. Por isso, algumas opções aparecem só em certos níveis.</p><hr><p>O nível também define os dados que a pessoa vê, como as vendas da unidade ou só as próprias.</p></div></aside>
</form>
<script>(function(level){
  var topics=[].slice.call(document.querySelectorAll('[data-permission-topic]'));
  function byLevel(){topics.forEach(function(topic){var open=0;topic.querySelectorAll('[data-roles]').forEach(function(label){var fits=label.dataset.roles.split(' ').indexOf(level.value)>=0;label.hidden=!fits;open+=fits?1:0});topic.hidden=!open})}
  topics.forEach(function(topic){var view=topic.querySelector('[data-permission-view]');topic.addEventListener('change',function(event){var boxes=topic.querySelectorAll('input');if(event.target===view){if(!view.checked)boxes.forEach(function(box){box.checked=false})}else if(event.target.checked)view.checked=true})});
  level.addEventListener('change',byLevel);byLevel();
})(document.querySelector('[data-permission-level]'))</script>
@endsection

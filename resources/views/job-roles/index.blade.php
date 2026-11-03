@extends('layouts.app')
@section('title','Cargos e permissões')
@section('content')
<a class="back-link" href="{{ route('employees.index') }}">← Funcionários</a>
<div class="page-heading"><div><h1>Cargos e permissões</h1><p>Crie um cargo, escolha o que ele pode ver e fazer e atribua aos funcionários.</p></div><a class="button button-primary" href="{{ route('employees.roles.create') }}">@include('components.icon',['name'=>'plus']) Novo cargo</a></div>
<section class="card"><div class="table-scroll"><table><thead><tr><th>Cargo</th><th>Nível de acesso</th><th>Tópicos liberados</th><th>Funcionários</th><th></th></tr></thead><tbody>
@forelse($jobRoles as $jobRole)
<tr><td><strong>{{ $jobRole->name }}</strong></td><td>{{ $levels[$jobRole->base_role] ?? $jobRole->base_role }}</td><td>{{ collect(\App\Models\User::AREAS)->filter(fn($area,$key)=>$jobRole->allows($key.'.view'))->map(fn($area)=>$area[0])->implode(', ') }}</td><td>{{ $jobRole->users_count }}</td>
<td><div class="employee-actions"><a class="text-link" href="{{ route('employees.roles.edit',$jobRole) }}">Editar</a>@if(!$jobRole->users_count)<form method="POST" action="{{ route('employees.roles.destroy',$jobRole) }}">@csrf<button class="text-button text-danger" data-confirm="Excluir o cargo {{ $jobRole->name }}?">Excluir</button></form>@endif</div></td></tr>
@empty<tr><td colspan="5"><div class="empty-state">@include('components.icon',['name'=>'users'])<strong>Nenhum cargo criado.</strong><p>Sem cargo, cada funcionário segue o perfil de acesso do cadastro.</p></div></td></tr>@endforelse
</tbody></table></div></section>
@endsection

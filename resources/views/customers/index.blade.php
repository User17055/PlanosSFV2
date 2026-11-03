@extends('layouts.app')
@section('title','Cadastros')
@section('content')
<div class="page-heading">
    <div><h1>Tutores e pets</h1><p>Busque um tutor e abra a ficha para ver os pets, os planos e os pagamentos.</p></div>
    @if(in_array(auth()->user()->role,['central_admin','manager','sales']) && auth()->user()->canUse('customers.create'))
        <a class="button button-primary" href="{{ route('customers.create') }}">@include('components.icon',['name'=>'plus']) Novo tutor e pet</a>
    @endif
</div>
<form class="filter-bar" method="GET" action="{{ route('customers.index') }}">
    <div class="search-input">@include('components.icon',['name'=>'search'])<input aria-label="Buscar tutor cadastrado" name="q" value="{{ request('q') }}" placeholder="Nome, CPF ou e-mail do tutor"></div>
    <button class="button button-secondary" type="submit">Buscar</button>
    @if(request('q'))<a class="text-link" href="{{ route('customers.index') }}">Limpar busca</a>@endif
    <span class="filter-count">{{ $customers->total() }} tutores cadastrados</span>
</form>
<section class="card">
    <div class="table-scroll"><table>
        <thead><tr><th>Tutor</th><th>Pets nesta ficha</th><th>Situação financeira</th><th></th></tr></thead>
        <tbody>
        @forelse($customers as $customer)
            <tr>
                <td><div class="identity"><span class="avatar tone-blue">{{ mb_substr($customer->name,0,1) }}</span><div><a class="text-link" href="{{ route('customers.show',$customer) }}">{{ $customer->name }}</a><small>{{ $customer->document ?? 'Documento não informado' }} · {{ $customer->email }}</small></div></div></td>
                <td><div class="pet-names">@forelse($customer->pets as $pet)<span>{{ $pet->name }}</span>@empty<span class="muted">Adicione o primeiro pet na ficha</span>@endforelse</div></td>
                <td>@php $state=data_get($financialStates,$customer->id.'.status','pending'); @endphp @include('components.status',['status'=>$state,'statusLabel'=>['regular'=>'Regular','blocked'=>'Benefícios bloqueados','pending'=>'Verificação pendente'][$state] ?? 'Verificação pendente'])</td>
                <td><a class="button button-secondary button-small" href="{{ route('customers.show',$customer) }}">Abrir ficha @include('components.icon',['name'=>'chevron'])</a></td>
            </tr>
        @empty
            <tr><td colspan="4"><div class="empty-state">@include('components.icon',['name'=>'users'])<strong>{{ request('q') ? 'Nenhum tutor encontrado.' : 'Cadastre o primeiro tutor e seu pet.' }}</strong><p>{{ request('q') ? 'Confira o nome, o CPF ou o e-mail antes de criar outro cadastro.' : 'Os próximos pets e contratos serão incluídos na mesma ficha.' }}</p></div></td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="pagination-wrapper">{{ $customers->links() }}</div>
</section>
@endsection

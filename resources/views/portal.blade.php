@extends('layouts.app')
@section('title','Meu cuidado')
@section('content')
@php
$state=data_get($financialState,'status','pending');
@endphp
@php $activePlans=$customer->subscriptions->reject(fn($subscription)=>$subscription->isTerminal())->count(); $petCount=$customer->pets->count(); @endphp
<section class="portal-hero"><div class="portal-hero-text"><span class="portal-hero-eyebrow">Mais vida para viver juntos</span><h1>Olá, {{ \Illuminate\Support\Str::title(mb_strtolower(explode(' ',$customer->name)[0])) }}</h1><p>Aqui você acompanha os planos dos seus pets e os pagamentos.</p><ul class="portal-hero-facts"><li><strong>{{ $petCount }}</strong> {{ $petCount===1?'pet':'pets' }}</li><li><strong>{{ $activePlans }}</strong> {{ $activePlans===1?'plano ativo':'planos ativos' }}</li></ul></div><div class="portal-hero-actions"><a class="button portal-hero-cta" href="{{ route('proposals.create') }}">@include('components.icon',['name'=>'plus']) Contratar um plano</a><a class="button portal-hero-ghost" href="{{ route('customers.edit',$customer) }}">@include('components.icon',['name'=>'users']) Meus dados</a></div></section>

@if($state==='blocked' || $pendingProposals->isNotEmpty())
<section class="portal-section" aria-labelledby="portal-actions-title"><h2 class="portal-section-title" id="portal-actions-title">Precisa da sua atenção</h2>
@if($state==='blocked')<div class="alert alert-danger portal-todo">@include('components.icon',['name'=>'alert'])<div><strong>Há {{ $money(data_get($financialState,'overdue_cents',0)) }} em pagamentos vencidos</strong><p>Enquanto isso, os benefícios dos seus pets ficam bloqueados. Pague a fatura em aberto para liberar.</p></div><a class="button button-primary" href="{{ route('portal.invoices') }}">Regularizar</a></div>@endif
@foreach($pendingProposals as $proposal)<div class="alert alert-info portal-todo">@include('components.icon',['name'=>'file'])<div><strong>{{ $proposal->productVersion->name }} para {{ $proposal->pet->name }} aguarda sua confirmação</strong><p>Leia e assine o contrato até {{ $proposal->expires_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}. Proposta {{ $proposal->number }}.</p></div><a class="button button-primary" href="{{ route('proposals.show',$proposal) }}">Revisar e assinar</a></div>@endforeach
</section>
@endif

<section class="portal-section" aria-labelledby="portal-pets-title"><h2 class="portal-section-title" id="portal-pets-title">Seus pets</h2>
<div class="portal-pets">
@forelse($customer->pets as $pet)
@php $current=$activeSubscriptions->where('pet_id',$pet->id)->values(); $age=$ageLabel($pet); $overdueCents=$openInvoices->filter(fn($invoice)=>$invoice->due_at->isPast() && data_get($invoice,'subscription.pet_id')===$pet->id)->sum(fn($invoice)=>$invoice->outstanding_cents); @endphp
<a class="card portal-pet-link{{ $overdueCents>0?' has-overdue':'' }}" href="{{ route('portal.pet',$pet) }}">
<div class="portal-pet-thumb">@if($pet->photo_path)<img class="pet-photo" src="{{ route('pets.photo',[$pet,'v'=>$pet->updated_at?->timestamp]) }}" alt="Foto de {{ $pet->name }}" width="88" height="88">@else<img class="pet-avatar pet-avatar-species" src="{{ asset('assets/brand/pet-'.($pet->species==='cat'?'gato':'cao').'.png') }}" alt="" width="88" height="88">@endif</div>
<div class="portal-pet-info"><h3>{{ $pet->name }}</h3><ul class="portal-pet-tags"><li>{{ ['dog'=>'Cão','cat'=>'Gato'][$pet->species] ?? $pet->species }}</li>@if($age)<li>{{ $age }}</li>@endif</ul>
@if($current->isNotEmpty())@php $activeCount=$current->where('status','active')->count(); @endphp<div class="portal-pet-plan"><span class="portal-pet-plan-eyebrow">@if($activeCount)<span class="portal-pet-plan-dot" aria-hidden="true"></span>{{ $activeCount }} {{ $activeCount===1?'plano ativo':'planos ativos' }}@else Plano em ativação @endif</span>@foreach($current as $subscription)<strong class="portal-pet-plan-name">{{ $subscription->productVersion->name }}</strong>@endforeach</div>@endif @foreach($pendingSubscriptions->where('pet_id',$pet->id) as $waiting)<p class="portal-pet-pending"><span class="portal-pet-pending-chip">Aguardando validação da proposta</span><span>{{ $waiting->productVersion->name }}</span></p>@endforeach @if($current->isEmpty() && $pendingSubscriptions->where('pet_id',$pet->id)->isEmpty())<p class="portal-pet-plans">Ainda sem plano contratado</p>@endif @if($overdueCents>0)<p class="portal-pet-overdue">{{ $money($overdueCents) }} vencidos · regularize em Faturas</p>@endif<div class="portal-pet-foot">@if($overdueCents>0 || $current->isNotEmpty() || $pendingSubscriptions->where('pet_id',$pet->id)->isEmpty())<span class="portal-pet-status{{ $overdueCents>0?' is-overdue':($current->isNotEmpty()?' is-active':'') }}">{{ $overdueCents>0?'Em atraso':($current->isNotEmpty()?'Com plano':'Sem plano') }}</span>@endif<span class="portal-pet-go">Ver plano</span></div></div>
</a>
@empty
<div class="card empty-state">@include('components.icon',['name'=>'heart'])<strong>Nenhum pet cadastrado ainda.</strong><p>Seu pet é cadastrado quando você contrata o primeiro plano.</p><a class="button button-primary button-small" href="{{ route('proposals.create') }}">Contratar um plano</a></div>
@endforelse
</div>
</section>

@endsection

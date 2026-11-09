@extends('layouts.app')
@section('title','Meu cuidado')
@section('content')
@php
$state=data_get($financialState,'status','pending');
@endphp
@php $activePlans=$customer->subscriptions->reject(fn($subscription)=>$subscription->isTerminal())->count(); $petCount=$customer->pets->count(); @endphp
<section class="portal-hero is-home"><div class="portal-hero-text"><span class="portal-hero-eyebrow">Mais vida para viver juntos</span><h1>Olá, {{ \Illuminate\Support\Str::title(mb_strtolower(explode(' ',$customer->name)[0])) }}</h1><p>Aqui você acompanha os planos dos seus pets e os pagamentos.</p><ul class="portal-hero-facts"><li><strong>{{ $petCount }}</strong> {{ $petCount===1?'pet':'pets' }}</li><li><strong>{{ $activePlans }}</strong> {{ $activePlans===1?'plano ativo':'planos ativos' }}</li></ul></div><div class="portal-hero-actions"><a class="button portal-hero-cta" href="{{ route('proposals.create') }}">Contratar um plano</a><a class="button portal-hero-ghost" href="{{ route('customers.edit',$customer) }}">@include('components.icon',['name'=>'users']) Meus dados</a></div></section>

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
<div class="portal-pet-info">
@php
$activeCount=$current->where('status','active')->count(); $waitingCount=$current->count()-$activeCount;
// Características do pet, sem repetir o nome (há cadastros com o nome digitado também na raça ou na cor).
$traits=collect([['dog'=>'Cão','cat'=>'Gato'][$pet->species] ?? $pet->species,$pet->breed,['male'=>'Macho','female'=>'Fêmea'][$pet->sex] ?? null,$pet->color,$age,$pet->birth_date?'Nasc. '.$pet->birth_date->format('d/m/Y'):null])
    ->filter()->reject(fn($trait)=>mb_strtolower(trim($trait))===mb_strtolower(trim($pet->name)))->values();
@endphp
<div class="portal-pet-head"><h3>{{ $pet->name }}</h3>@if($activeCount || $waitingCount || $overdueCents>0)<div class="portal-pet-pills">@if($activeCount)<span class="portal-pet-pill">{{ $activeCount }} {{ $activeCount===1?'plano ativo':'planos ativos' }}</span>@endif @if($waitingCount)<span class="portal-pet-pill is-waiting">{{ $waitingCount }} em ativação</span>@endif @if($overdueCents>0)<span class="portal-pet-pill is-overdue">Em atraso</span>@endif</div>@endif</div>
<ul class="portal-pet-tags">@foreach($traits as $trait)<li>{{ $trait }}</li>@endforeach</ul>
@foreach($pendingSubscriptions->where('pet_id',$pet->id) as $waiting)<p class="portal-pet-pending"><span class="portal-pet-pending-chip">Aguardando validação da proposta</span><span>{{ $waiting->productVersion->name }}</span></p>@endforeach @if($current->isEmpty() && $pendingSubscriptions->where('pet_id',$pet->id)->isEmpty())<p class="portal-pet-plans">Ainda sem plano contratado</p>@endif @if($overdueCents>0)<p class="portal-pet-overdue">{{ $money($overdueCents) }} vencidos · regularize em Faturas</p>@endif
<span class="portal-pet-go portal-pet-go-bottom">{{ $current->isNotEmpty()?'Ver plano':'Ver detalhes' }} @include('components.icon',['name'=>'arrow'])</span></div>
</a>
@empty
<div class="card empty-state">@include('components.icon',['name'=>'heart'])<strong>Nenhum pet cadastrado ainda.</strong><p>Seu pet é cadastrado quando você contrata o primeiro plano.</p><a class="button button-primary button-small" href="{{ route('proposals.create') }}">Contratar um plano</a></div>
@endforelse
</div>
</section>
@if($activeSubscriptions->isNotEmpty())
<section class="portal-section" aria-label="Benefício Tudo pra Pet"><div class="card portal-tpp"><img class="portal-tpp-logo" src="{{ asset('assets/brand/tpp-logo.svg') }}" alt="Tudo pra Pet" width="174" height="30"><div class="portal-tpp-text"><strong>Preços exclusivos na Tudo pra Pet</strong><p>Quem tem plano São Francisco também tem preços especiais na Tudo pra Pet para cuidar do seu pet em casa.</p></div></div></section>
@endif

@endsection

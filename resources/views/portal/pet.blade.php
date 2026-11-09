@extends('layouts.app')
@section('title',$pet->name)
@section('content')
@php
[$history,$current]=$customer->subscriptions->where('pet_id',$pet->id)->partition(fn($subscription)=>$subscription->isTerminal());
$age=$ageLabel($pet);
$petInvoices=$openInvoices->filter(fn($invoice)=>data_get($invoice,'subscription.pet_id')===$pet->id)->values();
$overdueCents=$petInvoices->filter(fn($invoice)=>$invoice->due_at->isPast())->sum(fn($invoice)=>$invoice->outstanding_cents);
$nextInvoice=$petInvoices->first();
$species=['dog'=>'Cão','cat'=>'Gato'][$pet->species] ?? $pet->species;
$awaiting=$pendingSubscriptions->where('pet_id',$pet->id)->values();
@endphp
<section class="portal-hero pet-hero-nav{{ $pet->photo_path ? ' has-art' : '' }}">
@if($pet->photo_path)<span class="pet-hero-art"><img src="{{ route('pets.photo',[$pet,'v'=>$pet->updated_at?->timestamp]) }}" alt="Foto de {{ $pet->name }}"></span>@else<div class="pet-hero-photo"><img class="pet-avatar pet-avatar-species" src="{{ asset('assets/brand/pet-'.($pet->species==='cat'?'gato':'cao').'.png') }}" alt="" width="88" height="88"></div>@endif
@if($pet->birth_date)<span class="pet-birth-corner">Nasc. {{ $pet->birth_date->format('d/m/Y') }}</span>@endif
<div class="portal-hero-text">
<span class="portal-hero-eyebrow"><span class="eyebrow-label">Seu pet</span>@if($pet->birth_date)<span class="pet-hero-birth"><span class="eyebrow-label"> · </span>Nasc. {{ $pet->birth_date->format('d/m/Y') }}</span>@endif</span>
<h1>{{ $pet->name }}</h1>
<ul class="portal-hero-facts"><li>{{ $species }}</li>@if($pet->breed)<li>{{ $pet->breed }}</li>@endif @if($age)<li>{{ $age }}</li>@endif</ul>
</div>
<div class="portal-hero-actions">
@if($current->isNotEmpty())<a class="button portal-hero-cta" href="{{ route('proposals.create',['pet_id'=>$pet->id]) }}">@include('components.icon',['name'=>'plus']) Contratar outro plano</a>@endif
<form method="POST" action="{{ route('pets.photo.store',$pet) }}" enctype="multipart/form-data">@csrf<label class="button portal-hero-ghost portal-photo-label">@include('components.icon',['name'=>'camera']) <span class="btn-label">{{ $pet->photo_path?'Trocar foto':'Adicionar foto' }}</span><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" hidden onchange="this.form.submit()"></label></form>
<a class="button portal-hero-ghost" href="{{ route('pets.edit',$pet) }}" aria-label="Atualizar dados de {{ $pet->name }}">@include('components.icon',['name'=>'edit']) <span class="btn-label">Editar dados</span></a>
</div>
</section>

@if($overdueCents>0)
<a class="pet-pay-strip is-overdue" href="{{ route('portal.invoices') }}"><strong>Em atraso · {{ $money($overdueCents) }}</strong><span>Regularize para liberar os benefícios de {{ $pet->name }}.</span><b>Ver faturas</b></a>
@elseif($nextInvoice)
@if($nextInvoice->is_initial)<a class="pet-pay-strip is-open" href="{{ route('portal.invoices') }}"><strong>Pagamento pendente · {{ $money($nextInvoice->outstanding_cents) }}</strong><span>Pague para ativar o plano de {{ $pet->name }}.</span><b>Pagar</b></a>@else<a class="pet-pay-strip is-open" href="{{ route('portal.invoices') }}"><strong>Próxima fatura · {{ $money($nextInvoice->outstanding_cents) }}</strong><span>Vence em {{ $date($nextInvoice->due_at) }}.</span><b>Ver faturas</b></a>@endif
@else
<a class="pet-pay-strip is-ok" href="{{ route('portal.invoices') }}"><strong>Pagamentos em dia</strong><span>Nenhuma fatura em aberto para {{ $pet->name }}.</span><b>Ver faturas</b></a>
@endif

<section class="portal-section" aria-labelledby="pet-plans-title"><h2 class="portal-section-title" id="pet-plans-title">{{ $current->count()>1?'Planos contratados':'Plano contratado' }}</h2>
<div class="pet-plans">
@foreach($awaiting as $waiting)@php $waitingInvoice=$openInvoices->first(fn($invoice)=>$invoice->subscription_id===$waiting->id); $waitingDocument=$waiting->contractDocuments->where('status','valid')->where('kind','final_contract')->sortByDesc('version')->first(); @endphp
<article class="card portal-awaiting"><span class="portal-pet-pending-chip">Aguardando validação da proposta</span><h3>{{ $waiting->productVersion->name }}</h3>
<p>Assinado em {{ $date($waiting->proposal?->accepted_at ?? $waiting->created_at) }}. Falta pagar.</p>
@if($waitingInvoice)<div class="portal-awaiting-pay"><span>{{ $money($waitingInvoice->outstanding_cents) }}</span>@if($waitingInvoice->payment_url)<a class="button button-primary" href="{{ route('portal.invoice.open',$waitingInvoice) }}" target="_blank" rel="noopener">Pagar agora</a>@else<a class="button button-primary" href="{{ route('portal.invoices') }}">Ver fatura</a>@endif</div>@endif
@if($waitingDocument)<a class="text-link portal-awaiting-doc" href="{{ route('contracts.download',$waitingDocument) }}">@include('components.icon',['name'=>'download']) Baixar contrato</a>@endif
</article>
@endforeach
@foreach($current as $subscription)
@include('portal.plan-card')
@endforeach
@if($current->isEmpty() && $awaiting->isEmpty())<div class="portal-pet-empty"><strong>{{ $pet->name }} ainda não tem um plano.</strong><p>Escolha o cuidado ideal para ele e contrate em poucos minutos.</p><a class="button button-primary button-small portal-pet-cta" href="{{ route('proposals.create',['pet_id'=>$pet->id]) }}">@include('components.icon',['name'=>'plus']) Contratar para {{ $pet->name }}</a></div>@endif
</div>
@if($history->isNotEmpty())<details class="card portal-disclosure pet-history"><summary>Planos encerrados ({{ $history->count() }})</summary><ul class="portal-list">@foreach($history as $subscription)@php $finalDocument=$subscription->contractDocuments->where('status','valid')->where('kind','final_contract')->sortByDesc('version')->first(); @endphp<li><div><a href="{{ route('portal.plan',[$pet,$subscription]) }}"><strong>{{ $subscription->productVersion->name }}</strong></a><small>{{ $subscription->cancelled_at?'Encerrado em '.$date($subscription->cancelled_at):($subscription->ends_at?'Encerrado em '.$date($subscription->ends_at):'Encerrado') }}</small></div>@if($finalDocument)<a class="text-link" href="{{ route('contracts.download',$finalDocument) }}">@include('components.icon',['name'=>'download']) Contrato</a>@endif</li>@endforeach</ul></details>@endif
</section>
@endsection

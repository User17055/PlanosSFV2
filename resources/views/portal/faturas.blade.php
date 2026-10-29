@extends('layouts.app')
@section('title','Faturas')
@section('content')
@php
$money=fn($cents)=>'R$ '.number_format($cents/100,2,',','.');
$date=fn($value)=>$value?->timezone('America/Sao_Paulo')->format('d/m/Y');
$state=data_get($financialState,'status','pending');
$openTotal=$openInvoices->sum(fn($invoice)=>$invoice->outstanding_cents);
$next=$openInvoices->first();
@endphp
<div class="page-heading"><div><h1>Faturas</h1><p>Acompanhe o que está em aberto e o histórico dos seus pagamentos.</p></div><div class="page-actions"><a class="button button-secondary" href="{{ route('portal') }}">← Meu cuidado</a></div></div>
@if($state==='blocked')<div class="alert alert-danger portal-todo">@include('components.icon',['name'=>'alert'])<div><strong>Há {{ $money(data_get($financialState,'overdue_cents',0)) }} em pagamentos vencidos</strong><p>Enquanto isso, os benefícios dos seus pets ficam bloqueados. Pague a fatura em aberto para liberar.</p></div></div>@endif
<div class="portal-invoice-summary">
<div class="card"><small>Em aberto</small><strong>{{ $openInvoices->isEmpty()?'Tudo pago':$money($openTotal) }}</strong></div>
<div class="card"><small>Próximo vencimento</small><strong>{{ $next?$date($next->due_at):'—' }}</strong></div>
<div class="card"><small>Pagamentos anteriores</small><strong>{{ $pastInvoices->count() }}</strong></div>
</div>
<section class="portal-section" aria-labelledby="faturas-abertas"><h2 class="portal-section-title" id="faturas-abertas">Em aberto</h2>
<div class="card portal-invoices">
@if($openInvoices->isEmpty())<p class="portal-invoices-clear">@include('components.icon',['name'=>'check']) {{ $pastInvoices->isEmpty()?'Nenhuma fatura por enquanto. Elas aparecem aqui depois da contratação.':'Tudo pago. Você não tem faturas em aberto.' }}</p>
@else @include('portal.invoices',['invoices'=>$openInvoices])@endif
</div></section>
@if($pastInvoices->isNotEmpty())
<section class="portal-section" aria-labelledby="faturas-historico"><h2 class="portal-section-title" id="faturas-historico">Histórico</h2>
<div class="card portal-invoices">@include('portal.invoices',['invoices'=>$pastInvoices])</div></section>
@endif
@endsection

@extends('layouts.app')
@section('title','Financeiro')
@section('content')
<div class="page-heading"><div><h1>Financeiro</h1><p>Faturas e pagamentos de cada contrato.</p></div><span class="badge badge-amber">@include('components.icon',['name'=>'clock']) Vindi em homologação</span></div>

<div class="metrics-grid three-metrics">
@foreach([['received_cents','Recebido no período','wallet','green','Pagas por inteiro'],['pending_cents','Em aberto','clock','blue','Esperando pagamento'],['overdue_cents','Saldo vencido','alert','ochre','Pode bloquear benefícios']] as [$metricKey,$metricLabel,$metricIcon,$metricTone,$metricDetail])
<div class="metric-card"><div class="metric-top"><span>{{ $metricLabel }}</span><span class="metric-icon tone-{{ $metricTone }}">@include('components.icon',['name'=>$metricIcon])</span></div><div class="metric-value">R$ {{ number_format(data_get($stats ?? [],$metricKey,0)/100,2,',','.') }}</div><span class="metric-detail">{{ $metricDetail }}</span></div>
@endforeach
</div>

<div class="alert alert-warning">@include('components.icon',['name'=>'info'])<div><strong>Só a Vindi confirma pagamento.</strong><p>Comprovante enviado pelo navegador não ativa o plano.</p></div></div>

<form method="GET" action="{{ route('finance.index') }}" class="filter-bar">
<div class="search-input">@include('components.icon',['name'=>'search'])<input name="q" value="{{ request('q') }}" aria-label="Buscar fatura ou tutor" placeholder="Buscar tutor ou referência de cobrança..."></div>
@if(request('customer_id'))<input type="hidden" name="customer_id" value="{{ request('customer_id') }}">@endif
<select name="status" aria-label="Situação financeira"><option value="">Todas as situações</option><option value="paid" @selected(request('status')==='paid')>Pagas</option><option value="pending" @selected(request('status')==='pending')>Aguardando pagamento</option><option value="overdue" @selected(request('status')==='overdue')>Vencidas</option></select>
<button class="button button-secondary">Filtrar faturas</button>
@if(request()->hasAny(['q','status','customer_id']))<a class="text-link" href="{{ route('finance.index') }}">Limpar filtros</a>@endif
</form>

<section class="card finance-records" aria-labelledby="finance-records-title">
<div class="card-heading"><div><h2 id="finance-records-title">Faturas e pagamentos</h2><p>Cada cobrança em um só lugar.</p></div></div>
@forelse($invoices as $invoice)
@php
    $overdue = !in_array($invoice->status,['paid','cancelled','refunded']) && $invoice->due_at->isPast() && $invoice->paid_cents<$invoice->amount_cents;
    $showPaymentLink = $invoice->payment_url && !in_array($invoice->status,['paid','cancelled','refunded']);
    $showDemoReceipt = !in_array($invoice->status,['paid','cancelled','refunded']) && config('plans.demo',false) && auth()->user()->canUse('finance.confirm') && in_array(auth()->user()->role,['central_admin','finance']) && Route::has('finance.confirm');
    $financeDate = fn($date) => $date?->copy()->timezone('America/Sao_Paulo')->format('d/m/Y');
@endphp
<article class="finance-record" aria-labelledby="invoice-title-{{ $invoice->id }}">
    <div class="finance-record-heading">
        <div class="finance-record-identity">
            <h3 id="invoice-title-{{ $invoice->id }}"><a href="{{ route('customers.show',$invoice->customer) }}">{{ $invoice->customer->name }}</a></h3>
            <p>{{ data_get($invoice,'subscription.pet.name') }} · {{ data_get($invoice,'subscription.productVersion.name') }}</p>
            <small>{{ $invoice->external_id ?? 'Fatura interna #'.$invoice->id }}{{ $invoice->is_initial?' · 1º pagamento':'' }} · {{ data_get($invoice,'originatingFranchise.name','Unidade de origem não informada') }}</small>
        </div>
        <div class="finance-record-status"><span class="finance-record-label">Situação financeira</span>@include('components.status',['status'=>$overdue?'overdue':$invoice->status])</div>
    </div>
    <dl class="finance-record-grid">
        <div class="finance-record-field"><dt>Competência</dt><dd>@if($invoice->competence_start){{ $financeDate($invoice->competence_start) }}@else<span class="muted">Início não informado</span>@endif<small>@if($invoice->competence_end)Até {{ $financeDate($invoice->competence_end) }}@else Término não informado @endif</small></dd></div>
        <div class="finance-record-field"><dt>Vencimento</dt><dd class="{{ $overdue?'text-danger':'' }}">{{ $financeDate($invoice->due_at) }}<small>{{ $invoice->paid_at ? 'Recebimento em '.$financeDate($invoice->paid_at) : 'Sem pagamento integral confirmado' }}</small></dd></div>
        <div class="finance-record-field"><dt>Valor cobrado / pago</dt><dd>R$ {{ number_format($invoice->amount_cents/100,2,',','.') }}<small>Pago: R$ {{ number_format($invoice->paid_cents/100,2,',','.') }}</small></dd></div>
        <div class="finance-record-field"><dt>Conciliação</dt><dd>@include('components.status',['status'=>$invoice->reconciliation_status])@if($invoice->confirmed_at)<small>Confirmada em {{ $financeDate($invoice->confirmed_at) }}</small>@else<small>Aguardando confirmação na origem</small>@endif</dd></div>
    </dl>
    <div class="finance-record-actions" aria-label="Ações da fatura {{ $invoice->external_id ?? $invoice->id }}">
        @if($showPaymentLink)<a class="button button-secondary button-small" href="{{ $invoice->payment_url }}" target="_blank" rel="noopener">Abrir fatura @include('components.icon',['name'=>'external'])</a>@endif
        @if($showDemoReceipt)<button class="button button-secondary button-small" data-dialog-open="payment-{{ $invoice->id }}" type="button">Registrar recebimento demo</button>
        @elseif(!$invoice->payment_url && $invoice->status!=='paid')<small class="muted">Checkout não disponível</small>
        @elseif($invoice->status==='paid')<span class="fine-print">@include('components.icon',['name'=>'check']) Pagamento confirmado</span>@endif
    </div>
</article>
@empty
<div class="empty-state">@include('components.icon',['name'=>'wallet'])<strong>Nenhuma fatura encontrada.</strong><p>As cobranças vinculadas às contratações aparecerão aqui.</p></div>
@endforelse
@if(method_exists($invoices,'links'))<div class="pagination-wrapper">{{ $invoices->withQueryString()->links() }}</div>@endif
</section>

@foreach($invoices as $invoice)
@if(!in_array($invoice->status,['paid','cancelled','refunded']) && in_array(auth()->user()->role,['central_admin','finance']) && auth()->user()->canUse('finance.confirm') && config('plans.demo') && Route::has('finance.confirm'))
<dialog class="modal" id="payment-{{ $invoice->id }}"><form method="POST" action="{{ route('finance.confirm',$invoice) }}">@csrf<div class="modal-heading"><h2>Recebimento demonstrativo</h2><button class="icon-button" type="button" data-dialog-close aria-label="Fechar">@include('components.icon',['name'=>'close'])</button></div><p>{{ $invoice->customer->name }} · Fatura #{{ $invoice->id }}</p><div class="alert alert-warning"><div>Só para demonstração. Não confirma pagamento na Vindi.</div></div><label class="field">Referência da transação <span>*</span><input name="reference" required minlength="5" placeholder="DEMO-TRANSACAO-001"></label><input type="hidden" name="amount_cents" value="{{ $invoice->amount_cents-$invoice->paid_cents }}"><label class="check-label"><input type="checkbox" required><span>Conferi o recebimento demonstrativo de R$ {{ number_format(($invoice->amount_cents-$invoice->paid_cents)/100,2,',','.') }} e compreendo que a ação será auditada.</span></label><div class="form-actions"><button type="button" class="button button-secondary" data-dialog-close>Voltar</button><button class="button button-primary">Registrar confirmação demo</button></div></form></dialog>
@endif
@endforeach
@endsection

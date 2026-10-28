@extends('layouts.app')
@section('title','Meu cuidado')
@section('content')
@php
$money=fn($cents)=>'R$ '.number_format($cents/100,2,',','.');
$date=fn($value)=>$value?->timezone('America/Sao_Paulo')->format('d/m/Y');
$state=data_get($financialState,'status','pending');
$ageLabel=function($pet){
    $months=$pet->birth_date?max(0,(int)$pet->birth_date->diffInMonths(now())):$pet->estimated_age_months;
    if($months===null) return null;
    $label=$months<12?$months.($months===1?' mês':' meses'):intdiv($months,12).(intdiv($months,12)===1?' ano':' anos');
    return $pet->birth_date?$label:'cerca de '.$label;
};
@endphp
<div class="page-heading"><div><h1>Olá, {{ \Illuminate\Support\Str::title(mb_strtolower(explode(' ',$customer->name)[0])) }}</h1><p>Aqui você acompanha os planos dos seus pets e os pagamentos.</p></div><div class="page-actions"><a class="button button-primary" href="{{ route('proposals.create') }}">@include('components.icon',['name'=>'plus']) Contratar um plano</a><a class="button button-secondary" href="{{ route('customers.edit',$customer) }}">@include('components.icon',['name'=>'users']) Meus dados</a></div></div>
@if(!auth()->user()->email_verified_at)<div class="alert alert-warning">@include('components.icon',['name'=>'mail'])<div><strong>Confirme seu e-mail para concluir contratações.</strong><p>Use o link enviado ao seu e-mail. Seus dados e propostas continuam disponíveis aqui.</p></div>@if(Route::has('verification.resend'))<form method="POST" action="{{ route('verification.resend') }}">@csrf<button class="button button-secondary button-small">Reenviar confirmação</button></form>@endif</div>@endif

@if($state==='blocked' || $pendingProposals->isNotEmpty())
<section class="portal-section" aria-labelledby="portal-actions-title"><h2 class="portal-section-title" id="portal-actions-title">Precisa da sua atenção</h2>
@if($state==='blocked')<div class="alert alert-danger portal-todo">@include('components.icon',['name'=>'alert'])<div><strong>Há {{ $money(data_get($financialState,'overdue_cents',0)) }} em pagamentos vencidos</strong><p>Enquanto isso, os benefícios dos seus pets ficam bloqueados. Pague a fatura em aberto para liberar.</p></div><a class="button button-primary" href="#minhas-faturas">Regularizar</a></div>@endif
@foreach($pendingProposals as $proposal)<div class="alert alert-info portal-todo">@include('components.icon',['name'=>'file'])<div><strong>{{ $proposal->productVersion->name }} para {{ $proposal->pet->name }} aguarda sua confirmação</strong><p>Leia e assine o contrato até {{ $proposal->expires_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}. Proposta {{ $proposal->number }}.</p></div><a class="button button-primary" href="{{ route('proposals.show',$proposal) }}">Revisar e assinar</a></div>@endforeach
</section>
@endif

<section class="portal-section" aria-labelledby="portal-pets-title"><h2 class="portal-section-title" id="portal-pets-title">Seus pets</h2>
<div class="portal-pets">
@forelse($customer->pets as $pet)
@php [$history,$current]=$customer->subscriptions->where('pet_id',$pet->id)->partition(fn($subscription)=>$subscription->isTerminal()); $age=$ageLabel($pet); @endphp
<article class="card portal-pet">
<div class="portal-pet-heading"><span class="pet-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($pet->name,0,1)) }}</span><div><h3>{{ $pet->name }}</h3><p>{{ ['dog'=>'Cão','cat'=>'Gato'][$pet->species] ?? $pet->species }}{{ $pet->breed?' · '.$pet->breed:'' }}{{ $age?' · '.$age:'' }}</p></div><a class="text-link" href="{{ route('pets.edit',$pet) }}" aria-label="Atualizar dados de {{ $pet->name }}">@include('components.icon',['name'=>'edit']) Editar</a></div>
@foreach($current as $subscription)@include('portal.subscription')@endforeach
@if($current->isEmpty())<div class="portal-pet-empty"><p>{{ $pet->name }} ainda não tem um plano.</p><a class="button button-secondary button-small" href="{{ route('proposals.create',['pet_id'=>$pet->id]) }}">@include('components.icon',['name'=>'plus']) Contratar para {{ $pet->name }}</a></div>@endif
@if($history->isNotEmpty())<details class="portal-disclosure"><summary>Planos encerrados ({{ $history->count() }})</summary><ul class="portal-list">@foreach($history as $subscription)@php $finalDocument=$subscription->contractDocuments->where('status','valid')->where('kind','final_contract')->sortByDesc('version')->first(); @endphp<li><div><strong>{{ $subscription->productVersion->name }}</strong><small>{{ $subscription->cancelled_at?'Encerrado em '.$date($subscription->cancelled_at):($subscription->ends_at?'Encerrado em '.$date($subscription->ends_at):'Encerrado') }}</small></div>@if($finalDocument)<a class="text-link" href="{{ route('contracts.download',$finalDocument) }}">@include('components.icon',['name'=>'download']) Contrato</a>@endif</li>@endforeach</ul></details>@endif
</article>
@empty
<div class="card empty-state">@include('components.icon',['name'=>'heart'])<strong>Nenhum pet cadastrado ainda.</strong><p>Seu pet é cadastrado quando você contrata o primeiro plano.</p><a class="button button-primary button-small" href="{{ route('proposals.create') }}">Contratar um plano</a></div>
@endforelse
</div>
</section>

<section class="portal-section" id="minhas-faturas" aria-labelledby="portal-invoices-title"><h2 class="portal-section-title" id="portal-invoices-title">Pagamentos</h2>
<div class="card portal-invoices">
@if($openInvoices->isEmpty())<p class="portal-invoices-clear">@include('components.icon',['name'=>'check']) {{ $pastInvoices->isEmpty()?'Nenhuma fatura por enquanto. Elas aparecem aqui depois da contratação.':'Tudo pago. Você não tem faturas em aberto.' }}</p>
@else @include('portal.invoices',['invoices'=>$openInvoices])@endif
@if($pastInvoices->isNotEmpty())<details class="portal-disclosure"><summary>Pagamentos anteriores ({{ $pastInvoices->count() }})</summary>@include('portal.invoices',['invoices'=>$pastInvoices])</details>@endif
</div>
</section>

<p class="portal-help">@include('components.icon',['name'=>'heart'])<span><strong>Dúvidas?</strong> @if($unit)Fale com a {{ $unit->name }}{{ $unit->address?' · '.$unit->address:'' }}.@else Fale com a sua unidade São Francisco.@endif</span></p>
@endsection

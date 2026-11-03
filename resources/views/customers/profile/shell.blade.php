@php
$canEdit=in_array(auth()->user()->role,['central_admin','manager','sales'],true) && auth()->user()->canUse('customers.update');
$canFinance=in_array(auth()->user()->role,['central_admin','manager','finance'],true) && auth()->user()->canUse('finance.index');
$state=data_get($financialState,'status','pending');
$customerErrors=$errors->getBag('customerEdit')->any();
$currentAddress=\App\Services\CustomerAddress::display($customer->address);
$money=fn($cents)=>'R$ '.number_format($cents/100,2,',','.');
$liveContracts=$customer->subscriptions->reject(fn($subscription)=>$subscription->isTerminal());
$pastContracts=$customer->subscriptions->filter(fn($subscription)=>$subscription->isTerminal());
$activeContracts=$liveContracts->whereIn('status',['active','scheduled_cancellation']);
$openProposals=$customer->proposals->filter(fn($proposal)=>!$proposal->locked_at && in_array($proposal->status,['draft','awaiting_acceptance']) && $proposal->expires_at?->isFuture());
$pastProposals=$customer->proposals->reject(fn($proposal)=>$openProposals->contains('id',$proposal->id));
$pendingDocuments=$liveContracts->where('document_status','!=','valid');
$openInvoices=$canFinance?$customer->invoices->whereNotIn('status',['cancelled','refunded'])->filter(fn($invoice)=>$invoice->outstanding_cents>0):collect();
$overdueInvoices=$openInvoices->filter(fn($invoice)=>$invoice->due_at?->isPast());
$initialPanel='resumo';
$profileHasErrors=$customerErrors || $errors->getBag('petCreate')->any() || $customer->pets->contains(fn($pet)=>$errors->getBag('petEdit'.$pet->id)->any());
if($customerErrors || request('edit')==='tutor')$initialPanel='tutor';
elseif($errors->getBag('petCreate')->any() || str_starts_with((string)request('edit'),'pet-') || $customer->pets->contains(fn($pet)=>$errors->getBag('petEdit'.$pet->id)->any()))$initialPanel='pets';
$cancelErrorId=$errors->any()?old('profile_cancel_subscription_id'):null;
if($cancelErrorId && $liveContracts->contains('id',(int)$cancelErrorId)){$initialPanel='contratos';$profileHasErrors=true;}
@endphp
<a class="back-link" href="{{ route('customers.index') }}">← Voltar para tutores e pets</a>
<div data-customer-profile data-profile-initial="{{ $initialPanel }}" data-profile-errors="{{ $profileHasErrors?'true':'false' }}">
<header class="customer-profile-hero">
    <span class="customer-profile-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($customer->name,0,1)) }}</span>
    <div class="customer-profile-identity"><div class="eyebrow">Tutor nº {{ str_pad($customer->id,4,'0',STR_PAD_LEFT) }}</div><h1>{{ $customer->name }}</h1><p>{{ $customer->phone ?: 'Telefone não informado' }}<span aria-hidden="true"> · </span>{{ $customer->email ?: 'E-mail não informado' }}</p></div>
    <div class="customer-profile-actions">
    @if($canEdit)
        @if($customer->pets->isEmpty())<a class="button button-primary" href="#novo-pet">@include('components.icon',['name'=>'plus']) Adicionar primeiro pet</a>
        @else<a class="button button-primary" href="#pets">@include('components.icon',['name'=>'heart']) Contratar para um pet</a><a class="button button-secondary" href="#novo-pet">@include('components.icon',['name'=>'plus']) Adicionar pet</a>@endif
    @endif
    <a class="text-link" href="#tutor">Ver dados do tutor →</a></div>
</header>
@if($state==='blocked')<div class="alert alert-danger profile-critical" role="status">@include('components.icon',['name'=>'alert'])<div><strong>Serviços e benefícios bloqueados por pendência financeira.</strong><p>O bloqueio abrange os pets deste tutor em toda a rede. A regularização precisa ser confirmada antes do atendimento.</p></div>@if($canFinance)<a class="button button-secondary button-small" href="#financeiro">Ver pagamentos</a>@endif</div>@endif
<nav class="customer-profile-tabs" data-profile-tabs aria-label="Seções da ficha">
@foreach([['resumo','Resumo',null],['pets','Pets',$customer->pets->count()],['contratos','Planos e contratos',$customer->subscriptions->count()],['financeiro','Financeiro',null],['propostas','Propostas',$openProposals->count()],['tutor','Dados do tutor',null]] as [$panelId,$panelLabel,$panelCount])
@if($panelId!=='financeiro' || $canFinance)<a id="profile-tab-{{ $panelId }}" href="#{{ $panelId }}" data-profile-tab="{{ $panelId }}">{{ $panelLabel }}@if($panelCount!==null)<span>{{ $panelCount }}</span>@endif</a>@endif
@endforeach
</nav>
<div class="customer-profile-panels">
    @include('customers.profile.overview')
    @include('customers.profile.pets')
    @include('customers.profile.contracts')
    @if($canFinance)@include('customers.profile.finance')@endif
    @include('customers.profile.proposals')
    @include('customers.profile.data')
</div>
</div>
@push('scripts')<script defer src="{{ asset('assets/customer-profile.js') }}?v={{ filemtime(public_path('assets/customer-profile.js')) }}"></script>@endpush

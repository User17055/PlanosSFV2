<section id="contratos" data-profile-panel="contratos" class="card workspace-section form-card">
<div class="profile-section-title"><div><h2>Planos e contratos</h2></div></div>
<div class="workspace-stack">@include('customers.partials.subscriptions',['subscriptionsToShow'=>$liveContracts])</div>
@if($pastContracts->isNotEmpty())<details class="workspace-details profile-history" id="historico-contratos"><summary>@include('components.icon',['name'=>'clock']) Histórico de contratos encerrados ({{ $pastContracts->count() }})</summary><div class="workspace-stack profile-history-body">@include('customers.partials.subscriptions',['subscriptionsToShow'=>$pastContracts])</div></details>@endif
</section>

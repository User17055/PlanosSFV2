<section id="propostas" data-profile-panel="propostas" class="card workspace-section form-card">
<div class="profile-section-title"><div><h2>Propostas em andamento</h2></div></div>
@include('customers.profile.proposal-list',['proposalRows'=>$openProposals])
@if($pastProposals->isNotEmpty())<details class="workspace-details profile-history" id="historico-propostas"><summary>@include('components.icon',['name'=>'clock']) Histórico de propostas ({{ $pastProposals->count() }})</summary><div class="profile-history-body"><p class="fine-print">Propostas aceitas, canceladas ou expiradas. Os contratos formalizados também estão na aba Planos e contratos.</p>@include('customers.profile.proposal-list',['proposalRows'=>$pastProposals])</div></details>@endif
</section>

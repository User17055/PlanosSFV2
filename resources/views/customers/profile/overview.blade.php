<section id="resumo" data-profile-panel="resumo">
<div class="profile-section-title"><div><h2>O que você precisa saber agora</h2></div></div>
<div class="profile-snapshot">
    <a href="#pets"><div><small>Pets cadastrados</small><strong>{{ $customer->pets->count() }}</strong></div><span>Ver pets →</span></a>
    <a href="#contratos"><span class="quick-icon tone-green">@include('components.icon',['name'=>'heart'])</span><div><small>Contratos ativos</small><strong>{{ $activeContracts->count() }}</strong></div><span>Ver planos →</span></a>
    <a href="#propostas"><span class="quick-icon tone-blue">@include('components.icon',['name'=>'file'])</span><div><small>Propostas em andamento</small><strong>{{ $openProposals->count() }}</strong></div><span>Ver propostas →</span></a>
</div>
<div class="profile-overview-grid">
<section class="card profile-next-steps"><div class="card-heading"><div><h3>Continue por aqui</h3></div></div>
@php $nextSteps=0; @endphp
@foreach($pendingDocuments->take(3) as $subscription)
@php $nextSteps++; @endphp
<div class="profile-task"><span class="quick-icon tone-ochre">@include('components.icon',['name'=>'file'])</span><div><strong>Concluir documentação de {{ $subscription->pet->name }}</strong><p>{{ $subscription->productVersion->name }} · documento {{ $subscription->document_status==='rejected'?'precisa de correção':'pendente' }}.</p></div><a class="button button-secondary button-small" href="{{ route('proposals.show',$subscription->proposal_id) }}">Acompanhar</a></div>
@endforeach
@foreach($openProposals->take(max(0,3-$nextSteps)) as $proposal)
@php $nextSteps++; @endphp
<div class="profile-task"><span class="quick-icon tone-blue">@include('components.icon',['name'=>'link'])</span><div><strong>Continuar proposta de {{ $proposal->pet->name }}</strong><p>{{ $proposal->productVersion->name }} · aguardando revisão e assinatura.</p></div><a class="button button-secondary button-small" href="{{ route('proposals.show',$proposal) }}">Abrir proposta</a></div>
@endforeach
@foreach($liveContracts->where('status','awaiting_regularization')->where('document_status','valid')->take(max(0,3-$nextSteps)) as $subscription)
@php $nextSteps++; @endphp
<div class="profile-task"><span class="quick-icon tone-ochre">@include('components.icon',['name'=>'clock'])</span><div><strong>Ativação pendente para {{ $subscription->pet->name }}</strong><p>Contrato pronto. Confira o pagamento e os requisitos da contratação.</p></div><a class="button button-secondary button-small" href="{{ route('proposals.show',$subscription->proposal_id) }}">Ver situação</a></div>
@endforeach
@if(!$nextSteps)<div class="profile-empty-summary">@if($customer->pets->isNotEmpty())<span class="quick-icon tone-green">@include('components.icon',['name'=>'check'])</span>@endif
<strong>{{ $customer->pets->isEmpty()?'Comece adicionando o primeiro pet.':'Nenhuma contratação pendente neste acesso.' }}</strong><p>{{ $customer->pets->isEmpty()?'O tutor já está cadastrado. Adicione o pet para preparar a contratação.':'Use a aba Pets para um novo atendimento ou consulte os planos existentes.' }}</p>@if($canEdit)<a class="text-link" href="{{ $customer->pets->isEmpty()?'#novo-pet':'#pets' }}">{{ $customer->pets->isEmpty()?'Adicionar primeiro pet':'Ver pets do tutor' }} →</a>@endif</div>@endif
</section>
<section class="card profile-financial-summary"><h3>{{ ['blocked'=>'Há pendências para regularizar','regular'=>'Situação financeira regular','pending'=>'Verificação pendente'][$state]??'Verificação pendente' }}</h3><p>{{ $state==='regular'?'A confirmação de atendimento também depende da vigência, das carências e do saldo de cada benefício.':($state==='blocked'?'Consulte os pagamentos para acompanhar a regularização.':'Aguarde a conciliação dos pagamentos. O cadastro, sozinho, não confirma a liberação dos serviços.') }}</p>@if($canFinance)<a class="button button-secondary button-small" href="#financeiro">Ver faturas e pagamentos</a>@endif<small>Última consulta: {{ data_get($financialState,'last_checked_at')?\Illuminate\Support\Carbon::parse($financialState['last_checked_at'])->timezone('America/Sao_Paulo')->format('d/m/Y H:i'):'Ainda não realizada' }}</small></section>
</div>
<section class="card profile-overview-pets"><div class="card-heading"><div><h3>Pets deste tutor</h3></div><a class="text-link" href="#pets">Ver todos →</a></div><div class="profile-pet-shortcuts">@forelse($customer->pets->take(6) as $pet)<a href="#pet-{{ $pet->id }}"><div><strong>{{ $pet->name }}</strong><small>{{ ['dog'=>'Cão','cat'=>'Gato'][$pet->species]??$pet->species }} · {{ $liveContracts->where('pet_id',$pet->id)->count() }} contrato(s) em andamento</small></div>@include('components.icon',['name'=>'arrow'])</a>@empty<p class="muted">Nenhum pet cadastrado.</p>@endforelse</div></section>
</section>

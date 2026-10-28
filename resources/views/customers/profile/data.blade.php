<section id="tutor" data-profile-panel="tutor" class="card workspace-section form-card">
    <div class="profile-section-title"><div><h2>Dados do tutor</h2></div>@if($canEdit)<a class="button button-secondary button-small" href="#editar-tutor">@include('components.icon',['name'=>'edit']) Editar cadastro</a>@endif</div>
    <dl class="workspace-summary">
        <div><dt>Nome</dt><dd>{{ $customer->name }}</dd></div><div><dt>CPF / identificação</dt><dd>{{ $customer->document ?? 'Não informado' }}</dd></div>
        <div><dt>E-mail</dt><dd>{{ $customer->email ?? 'Não informado' }}</dd></div><div><dt>Telefone</dt><dd>{{ $customer->phone ?? 'Não informado' }}</dd></div>
        <div><dt>Endereço</dt><dd>{{ $currentAddress ?: 'Não informado' }}</dd></div>
    </dl>
    <dl class="workspace-summary"><div><dt>Como conheceu · cadastro</dt><dd>{{ \App\Services\Attribution\TrackingSanitizer::sourceLabels()[data_get($customer->acquisition_profile,'acquisition_source','unknown')]??'Não informado' }}</dd></div><div><dt>Quem recomendou</dt><dd>{{ data_get($customer->acquisition_profile,'referrer_name')?:'Não informado' }}</dd></div>@if(data_get($customer->acquisition_profile,'campaign_name'))<div><dt>Campanha ou parceria</dt><dd>{{ data_get($customer->acquisition_profile,'campaign_name') }}</dd></div>@endif</dl>
    <p class="fine-print">{{ $customer->contact_verified_at ? 'Contato verificado.' : 'Contato aguardando verificação do titular.' }}</p>
    @if($canEdit)
    <details id="editar-tutor" class="workspace-details" @if($customerErrors || request('edit')==='tutor') open @endif>
        <summary>@include('components.icon',['name'=>'edit']) Editar dados do tutor</summary>
        <form method="POST" action="{{ route('customers.update',$customer) }}">
            @csrf<input type="hidden" name="_workspace_form" value="tutor-edit">
            @include('customers.partials.errors',['bag'=>'customerEdit'])
            <div class="workspace-fields">
                <label class="field">Nome completo <span>*</span><input name="name" required minlength="3" maxlength="180" autocomplete="name" value="{{ $customerErrors?old('name',$customer->name):$customer->name }}"></label>
                <label class="field">Telefone <span>*</span><input name="phone" required maxlength="30" autocomplete="tel" value="{{ $customerErrors?old('phone',$customer->phone):$customer->phone }}"></label>
                
@include('customers.partials.contract-fields',['contractCustomer'=>$customer,'contractBag'=>'customerEdit'])
<div class="span-2">@include('components.customer-acquisition',['acquisitionCustomer'=>$customer,'acquisitionBag'=>'customerEdit'])</div>
                <label class="field">Motivo da atualização <span>*</span><textarea name="reason" required minlength="5" maxlength="1000" rows="2" placeholder="Ex.: telefone informado pelo tutor">{{ $customerErrors?old('reason'):'' }}</textarea></label>
            </div>
            <p class="fine-print">Esta alteração atualiza a ficha. Os contratos assinados mantêm os dados do momento do aceite.</p>
            <div class="form-actions"><button class="button button-primary">Salvar dados do tutor</button></div>
        </form>
    </details>
    @endif
</section>

<section id="pets" data-profile-panel="pets" class="card workspace-section form-card">
    <div class="profile-section-title"><div><h2>Pets deste tutor</h2></div>@if($canEdit)<a class="button button-secondary button-small" href="#novo-pet">@include('components.icon',['name'=>'plus']) Adicionar pet</a>@endif</div>
    @if($canEdit)
    <details class="workspace-details" id="novo-pet" @if($errors->getBag('petCreate')->any()) open @endif>
        <summary>@include('components.icon',['name'=>'plus']) Adicionar outro pet a esta ficha</summary>
        <form method="POST" action="{{ route('customers.pets.store',$customer) }}">
            @csrf<input type="hidden" name="_workspace_form" value="pet-add">
            @include('customers.partials.errors',['bag'=>'petCreate'])
            @include('customers.partials.pet-fields',['prefix'=>'','bag'=>'petCreate','pet'=>null,'editing'=>false])
            <div class="form-actions"><button class="button button-primary">Salvar pet nesta ficha</button></div>
        </form>
    </details>
    @endif
    <div class="workspace-stack">
    @forelse($customer->pets as $pet)
        @php
        $petContracts=$customer->subscriptions->where('pet_id',$pet->id);
        $petCurrent=$liveContracts->where('pet_id',$pet->id)->first();
        $petProposal=$openProposals->firstWhere('pet_id',$pet->id);
        $petBag='petEdit'.$pet->id;
        $petActionUrl=$petCurrent?'#assinatura-'.$petCurrent->id:($petProposal?route('proposals.show',$petProposal):route('proposals.create',['customer_id'=>$customer->id,'pet_id'=>$pet->id]));
        $petActionLabel=$petCurrent?'Ver plano ou pacote':($petProposal?'Continuar proposta':'Contratar plano');
        @endphp
        <article class="workspace-pet-card" id="pet-{{ $pet->id }}">
            <div class="workspace-pet-header">
                
                <div><h3>{{ $pet->name }}</h3><p>{{ ['dog'=>'Cão','cat'=>'Gato','canine'=>'Cão','feline'=>'Gato'][$pet->species] ?? $pet->species }}{{ $pet->breed?' · '.$pet->breed:'' }}</p><small>{{ $pet->birth_date?'Nascimento: '.$pet->birth_date->format('d/m/Y'):'Nascimento não informado' }}{{ $pet->microchip?' · Microchip: '.$pet->microchip:'' }}</small></div>
                @if($petCurrent || $petProposal || $canEdit)<a class="button button-primary button-small" href="{{ $petActionUrl }}">{{ $petActionLabel }} @include('components.icon',['name'=>'arrow'])</a>@endif
            </div>
            @if($petContracts->isNotEmpty())
            <div class="workspace-pet-summary"><strong>Planos e pacotes deste pet</strong>
                @foreach($liveContracts->where('pet_id',$pet->id) as $petContract)<div><a class="text-link" href="#assinatura-{{ $petContract->id }}">{{ $petContract->productVersion->name }} · {{ $petContract->category==='package'?'Pacote':'Plano de Saúde' }}</a> @include('components.status',['status'=>$petContract->status])</div>@endforeach
                @if($petContracts->contains(fn($contract)=>$contract->isTerminal()))<a class="text-link" href="#assinatura-{{ $petContracts->first(fn($contract)=>$contract->isTerminal())->id }}">Ver contratos encerrados deste pet →</a>@endif
            </div>
            @else<p class="fine-print">Nenhum contrato disponível para este pet no seu acesso.</p>@endif
            @if($canEdit)
            @if($petCurrent || $petProposal)<p class="profile-pet-new-product"><a class="text-link" href="{{ route('proposals.create',['customer_id'=>$customer->id,'pet_id'=>$pet->id]) }}">Ver opções para outra contratação →</a></p>@endif
            <details id="editar-pet-{{ $pet->id }}" class="workspace-details" @if($errors->getBag($petBag)->any() || request('edit')==='pet-'.$pet->id) open @endif>
                <summary>@include('components.icon',['name'=>'edit']) Editar dados de {{ $pet->name }}</summary>
                <form method="POST" action="{{ route('pets.update',$pet) }}">
                    @csrf<input type="hidden" name="_workspace_form" value="pet-edit-{{ $pet->id }}">
                    @include('customers.partials.errors',['bag'=>$petBag])
                    @include('customers.partials.pet-fields',['prefix'=>'','bag'=>$petBag,'pet'=>$pet,'editing'=>true])
                    <label class="field">Motivo da atualização <span>*</span><textarea name="reason" required minlength="5" maxlength="1000" rows="2" placeholder="Descreva a correção dos dados">{{ $errors->getBag($petBag)->any()?old('reason'):'' }}</textarea></label>
                    <p class="fine-print">A atualização preserva os contratos, o tutor responsável e a espécie cadastrada.</p>
                    <div class="form-actions"><button class="button button-primary">Salvar dados do pet</button></div>
                </form>
            </details>
            @endif
        </article>
    @empty
        <div class="empty-state"><strong>Adicione o primeiro pet nesta ficha.</strong><p>Use o formulário acima. O tutor já está cadastrado.</p></div>
    @endforelse
    </div>
</section>

<form method="POST" action="{{ route('proposals.regenerate-contract',$proposal) }}" class="section-gap" data-contract-recovery>
@csrf
<p role="status" aria-live="polite" data-recovery-status>Sua assinatura está registrada. Estamos preparando o PDF final.</p>
<button class="button button-secondary button-small" data-recovery-button>@include('components.icon',['name'=>'refresh']) Tentar gerar PDF novamente</button>
<p class="fine-print">Você não precisa assinar novamente. A data, as condições e as evidências do aceite original serão preservadas.</p>
</form>

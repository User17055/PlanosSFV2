@forelse($subscriptionsToShow ?? $customer->subscriptions as $subscription)
@include('customers.profile.subscription-card')
@empty
<div class="empty-state"><strong>Nenhum contrato em andamento neste acesso.</strong><p>Escolha um pet para preparar uma nova contratação.</p>@if($canEdit)<a class="text-link" href="#pets">Ver pets do tutor →</a>@endif</div>
@endforelse

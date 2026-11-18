@forelse($subscriptionsToShow ?? $customer->subscriptions as $subscription)
@include('customers.profile.subscription-card')
@empty
<div class="empty-state"><strong>Nenhum contrato em andamento neste acesso.</strong><p>Escolha um pet para preparar uma nova contratação.</p>@if($canEdit)<a class="text-link" href="#pets">Ver pets do tutor @include('components.bx',['name'=>'bx-right-arrow-alt','size'=>18])</a>@endif</div>
@endforelse

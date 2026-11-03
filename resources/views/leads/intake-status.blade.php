@php
$last=$intake['last'];
$ago=fn($date)=>$date->locale('pt_BR')->diffForHumans();
$quiet=$last && $last->created_at->lt(now()->subDays(2));
$refusedAt=$intake['refused'] ? \Illuminate\Support\Carbon::createFromTimestamp($intake['refused']['at']) : null;
$state=$intake['off'] ? 'off' : ($refusedAt || ($quiet && $intake['admin']) ? 'warn' : ($last ? 'ok' : 'idle'));
@endphp
<div class="lead-intake lead-intake-{{ $state }}" data-last-lead="{{ $last?->id }}">
@include('components.icon',['name'=>$state==='ok' ? 'check' : ($state==='idle' ? 'phone' : 'alert')])
<div>
<strong>{{ $last ? 'Último lead recebido '.$ago($last->created_at) : 'Nenhum lead recebido ainda' }}</strong>
@if($intake['off'])<p><b>Recebimento desligado:</b> falta configurar <code>LEADS_WEBHOOK_TOKEN</code> neste servidor. Enquanto isso, nenhum lead do Pluga entra.</p>@endif
@if($refusedAt)<p><b>Um envio foi recusado {{ $ago($refusedAt) }}</b> porque veio sem telefone nem e-mail reconhecível. Campos recebidos: {{ implode(', ',$intake['refused']['fields']) ?: 'nenhum' }}. Confira o mapeamento da automação no Pluga.</p>@endif
@if($quiet && $intake['admin'] && !$intake['off'])<p>Se os anúncios estão ativos, confira no histórico do Pluga se os envios estão chegando.</p>@endif
</div>
</div>

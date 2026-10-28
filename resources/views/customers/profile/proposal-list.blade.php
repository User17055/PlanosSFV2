<div class="table-scroll"><table><thead><tr><th>Pet e plano</th><th>Proposta</th><th>Situação</th><th>Ação</th></tr></thead><tbody>
@forelse($proposalRows as $proposal)
<tr><td><strong>{{ $proposal->pet->name }}</strong><small>{{ $proposal->productVersion->name }}</small></td><td>{{ $proposal->number }}</td><td>@include('components.status',['status'=>!$proposal->locked_at && $proposal->status!=='cancelled' && $proposal->expires_at?->isPast()?'expired':$proposal->status])</td><td><a class="text-link" href="{{ route('proposals.show',$proposal) }}">{{ $proposal->accepted_at?'Ver contratação':'Abrir proposta' }} →</a></td></tr>
@empty<tr><td colspan="4"><div class="empty-state"><strong>Nenhuma proposta em andamento.</strong><p>Para preparar uma contratação, escolha o pet.</p>@if($canEdit)<a class="text-link" href="#pets">Ir para os pets →</a>@endif</div></td></tr>@endforelse
</tbody></table></div>

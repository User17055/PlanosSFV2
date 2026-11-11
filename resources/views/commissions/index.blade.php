@extends('layouts.app')
@section('title','Comissões')
@section('content')
@php
$isSales=auth()->user()->role==='sales';
$money=fn($cents)=>'R$ '.number_format(($cents ?? 0)/100,2,',','.');
$sum=fn($status)=>(int) data_get($totals,$status.'.amount',0);
$count=fn($status)=>(int) data_get($totals,$status.'.total',0);
$channels=['site'=>'Site','link'=>'Link individual','presential'=>'Presencial'];
$statusLabels=\App\Models\Commission::STATUSES;
@endphp
<div class="page-heading"><div><h1>{{ $isSales ? 'Minhas comissões' : 'Comissões' }}</h1><p>Paga sobre a 1ª parcela confirmada. Venda sem vendedor identificado não gera comissão automática.</p></div></div>

<div class="metrics-grid">
<div class="metric-card"><div class="metric-top"><span>A pagar</span><span class="metric-icon tone-ochre">@include('components.icon',['name'=>'wallet'])</span></div><div class="metric-value">{{ $money($sum('payable')) }}</div><span class="metric-detail"><span class="small-dot ochre"></span>{{ $count('payable') }} comissão(ões) liberada(s)</span></div>
<div class="metric-card"><div class="metric-top"><span>Pagas</span><span class="metric-icon tone-green">@include('components.icon',['name'=>'check'])</span></div><div class="metric-value">{{ $money($sum('paid')) }}</div><span class="metric-detail"><span class="small-dot green"></span>{{ $count('paid') }} paga(s) ao beneficiário</span></div>
<div class="metric-card"><div class="metric-top"><span>Aguardando percentual</span><span class="metric-icon tone-blue">@include('components.icon',['name'=>'percent'])</span></div><div class="metric-value">{{ $count('awaiting_rate') + $count('held') }}</div><span class="metric-detail"><span class="small-dot blue"></span>Sem percentual ou em contestação</span></div>
<div class="metric-card"><div class="metric-top"><span>Estornadas</span><span class="metric-icon tone-purple">@include('components.icon',['name'=>'refresh'])</span></div><div class="metric-value">{{ $money($sum('reversed')) }}</div><span class="metric-detail"><span class="small-dot purple"></span>{{ $count('reversed') }} por estorno ou cancelamento</span></div>
</div>

<form class="card commission-filters" method="GET" action="{{ route('commissions.index') }}">
<label class="field">De<input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></label>
<label class="field">Até<input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></label>
<label class="field">Situação<select name="status"><option value="">Todas</option>@foreach($statusLabels as $value=>$label)<option value="{{ $value }}" @selected(($filters['status'] ?? '')===$value)>{{ $label }}</option>@endforeach</select></label>
@unless($isSales)<label class="field">Beneficiário<select name="beneficiary"><option value="">Todos</option>@foreach($beneficiaries as $b)@php $key=$b->beneficiary_type==='unassigned'?'unassigned':(string)$b->beneficiary_user_id; @endphp<option value="{{ $key }}" @selected(($filters['beneficiary'] ?? '')===$key)>{{ $b->beneficiary_name }}</option>@endforeach</select></label>@endunless
@if($franchises->count()>1)<label class="field">Unidade<select name="franchise_id"><option value="">Todas</option>@foreach($franchises as $f)<option value="{{ $f->id }}" @selected((int)($filters['franchise_id'] ?? 0)===$f->id)>{{ $f->name }}</option>@endforeach</select></label>@endif
<div class="commission-filter-actions"><button class="button button-secondary" type="submit">@include('components.icon',['name'=>'search']) Filtrar</button>@if(array_filter($filters))<a class="text-link" href="{{ route('commissions.index') }}">Limpar</a>@endif</div>
</form>

@php $period='De '.\Illuminate\Support\Carbon::parse($filters['date_from'])->format('d/m').' a '.\Illuminate\Support\Carbon::parse($filters['date_to'])->format('d/m/Y'); @endphp
<section class="card section-gap"><div class="card-heading"><div><h2>{{ $isSales ? 'Meu total no período' : 'Por funcionário' }}</h2><p>{{ $period }}. Soma das comissões liberadas, pagas e aguardando, sem as estornadas.</p></div></div>
<div class="table-scroll"><table><thead><tr><th>Funcionário</th><th>Contratos</th><th>Comissão total</th><th>A pagar</th><th>Pagas</th></tr></thead><tbody>
@forelse($people as $person)<tr><td>@unless($isSales)<a href="{{ route('commissions.index',array_merge(array_filter($filters),['beneficiary'=>$person->beneficiary_user_id])) }}"><strong>{{ $person->name }}</strong></a>@else<strong>{{ $person->name }}</strong>@endunless @if($person->waiting)<small>{{ $person->waiting }} aguardando percentual</small>@endif</td><td>{{ $person->contracts }}</td><td><strong>{{ $money($person->total) }}</strong></td><td>{{ $money($person->payable) }}</td><td>{{ $money($person->paid) }}</td></tr>
@empty<tr><td colspan="5"><div class="empty-state"><strong>Nenhuma comissão no período.</strong></div></td></tr>@endforelse
@if($people->count()>1)<tr class="commission-total"><td><strong>Total</strong></td><td>{{ $people->sum('contracts') }}</td><td><strong>{{ $money($people->sum('total')) }}</strong></td><td>{{ $money($people->sum('payable')) }}</td><td>{{ $money($people->sum('paid')) }}</td></tr>@endif
</tbody></table></div>
</section>

<section class="card section-gap"><div class="card-heading"><div><h2>Contratações e comissões</h2><p>Quem fez, de onde veio e quem recebe. O percentual e o valor ficam registrados no momento do primeiro pagamento.</p></div></div>
<div class="table-scroll"><table><thead><tr><th>Contratação</th><th>Quem fez e origem</th><th>Beneficiário</th><th>Comissão</th><th>Situação</th><th></th></tr></thead><tbody>
@forelse($rows as $c)
@php $o=$c->origin_snapshot ?? []; @endphp
<tr>
<td><a href="{{ route('proposals.show',$c->proposal_id) }}"><strong>{{ data_get($c,'subscription.customer.name','Tutor') }}</strong></a><small>{{ data_get($c,'subscription.pet.name') }} · {{ data_get($c,'productVersion.name') }}</small><small>{{ $o['proposal_number'] ?? '' }}{{ $c->is_demo ? ' · demonstração' : '' }}</small></td>
<td>@if($c->sold_by_id)<strong>{{ $o['creator_name'] ?? data_get($c,'soldBy.name') }}</strong>@else<strong>O próprio tutor</strong>@endif<small>{{ $channels[$o['channel'] ?? ''] ?? 'Canal não informado' }} · {{ $sourceLabels[$o['acquisition_source'] ?? 'unknown'] ?? 'Não informado' }}</small>@if(!empty($o['marketing_link_name']))<small>Link: {{ $o['marketing_link_name'] }}</small>@endif @if(!empty($o['campaign_name']))<small>Campanha: {{ $o['campaign_name'] }}</small>@endif @if(!empty($o['referrer_name']))<small>Indicação: {{ $o['referrer_name'] }}</small>@endif</td>
<td><strong>{{ $c->beneficiary_name }}</strong><small>{{ $c->rule_label }}</small></td>
<td><strong>{{ $c->status==='not_applicable' ? 'Sem comissão' : ($c->calculation_type===null ? 'Sem regra' : $money($c->amount_cents)) }}</strong><small>@if($c->calculation_type==='fixed')Valor fixo sobre o primeiro pagamento de {{ $money($c->base_cents) }}@elseif($c->calculation_type==='percent'){{ number_format($c->rate_basis_points/100,2,',','.') }}% de {{ $money($c->base_cents) }}@else Base: {{ $money($c->base_cents) }}@endif</small><small>1º pagamento em {{ $c->earned_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}</small></td>
<td>@include('components.status',['status'=>$c->status,'statusLabel'=>$c->status_label])
@if($c->status==='paid')<small>Em {{ $c->paid_at->timezone('America/Sao_Paulo')->format('d/m/Y') }} · {{ $c->payout_reference }}</small>@endif
@if($c->status==='reversed')<small>{{ $c->reversal_reason }}</small>@if($c->clawback)<small class="text-danger">Já tinha sido paga: descontar do beneficiário.</small>@endif @endif</td>
<td>@if($canPay && $c->status==='payable')<button class="button button-secondary button-small commission-pay" type="button" data-dialog-open="commission-pay-{{ $c->id }}">Marcar como paga</button>@endif</td>
</tr>
@empty
<tr><td colspan="6"><div class="empty-state">@include('components.icon',['name'=>'percent'])<strong>Nenhuma comissão por aqui.</strong><p>As comissões aparecem quando o primeiro pagamento de uma contratação é confirmado.</p></div></td></tr>
@endforelse
</tbody></table></div>
@if($rows->hasPages())<div class="pagination-wrapper">{{ $rows->links() }}</div>@endif
</section>

@if($canPay)
@foreach($rows->where('status','payable') as $c)
<dialog class="modal" id="commission-pay-{{ $c->id }}"><form method="POST" action="{{ route('commissions.pay',$c) }}">@csrf<div class="modal-heading"><h2>Registrar pagamento da comissão</h2><button type="button" class="icon-button" data-dialog-close aria-label="Fechar">@include('components.icon',['name'=>'close'])</button></div><p>{{ $c->beneficiary_name }} · {{ $money($c->amount_cents) }} · {{ data_get($c,'subscription.customer.name') }}</p><label class="field">Referência do pagamento ao beneficiário <span>*</span><input name="payout_reference" required minlength="3" maxlength="120" placeholder="Ex.: folha 10/2026, PIX 123"></label><div class="form-actions"><button type="button" class="button button-secondary" data-dialog-close>Voltar</button><button class="button button-primary" data-submit-label="Registrando...">Confirmar pagamento</button></div></form></dialog>
@endforeach
@endif

@if($rates->isNotEmpty())
<form class="card form-card section-gap" method="POST" action="{{ route('commissions.rates') }}">@csrf
<div class="form-section-heading"><span class="step-number">%</span><div><h2>Regra por plano e pacote</h2><p>Escolha percentual do primeiro pagamento ou um valor fixo.</p></div></div>
<div class="table-scroll"><table class="commission-rates"><thead><tr><th>Produto</th><th>Categoria</th><th>Cálculo</th><th>Valor</th><th>Vigente desde</th></tr></thead><tbody>
@foreach($rates as ['product'=>$product,'rate'=>$rate])
@php $rateType=$rate?->calculation_type ?? 'percent'; $rateValue=$rate ? ($rateType==='fixed' ? $rate->fixed_amount_cents/100 : $rate->rate_basis_points/100) : null; @endphp
<tr><td><strong>{{ $product->name }}</strong><small>{{ $product->code }}</small></td><td>{{ $product->category==='package' ? 'Pacote' : 'Plano de Saúde' }}</td><td><select name="calculation_types[{{ $product->id }}]" data-commission-type aria-label="Forma de cálculo de {{ $product->name }}"><option value="percent" @selected($rateType==='percent')>Percentual</option><option value="fixed" @selected($rateType==='fixed')>Valor fixo</option></select></td><td><label class="rate-input"><input type="number" name="values[{{ $product->id }}]" min="0" max="1000000" step="0.01" inputmode="decimal" value="{{ $rateValue===null ? '' : rtrim(rtrim(number_format($rateValue,2,'.',''),'0'),'.') }}" placeholder="—" aria-label="Valor da regra de comissão de {{ $product->name }}"><span data-commission-suffix>{{ $rateType==='fixed'?'R$':'%' }}</span></label></td><td>@if($rate){{ $rate->effective_from->timezone('America/Sao_Paulo')->format('d/m/Y') }}@if($rate->is_demo)<small class="text-danger">Valor de exemplo: defina a regra real</small>@endif @else<small>Sem regra: comissões identificadas aguardam definição</small>@endif</td></tr>
@endforeach
</tbody></table></div>
<label class="field section-gap">Motivo da alteração<input name="note" maxlength="180" placeholder="Opcional. Ex.: política comercial aprovada em outubro"></label>
<div class="form-actions"><span class="fine-print">Novas regras valem para os próximos primeiros pagamentos. Comissões já calculadas não mudam; as que aguardam regra são calculadas na hora.</span><button class="button button-primary" data-submit-label="Salvando...">Salvar regras</button></div>
</form>
@endif

<div class="bottom-note">@include('components.icon',['name'=>'shield'])<div><strong>Comissão segue o dinheiro.</strong><span> Ela só nasce quando o primeiro pagamento é confirmado e é estornada automaticamente se esse pagamento for estornado ou cancelado.</span></div></div>
@endsection

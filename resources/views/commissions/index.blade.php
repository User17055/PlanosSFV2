@extends('layouts.app')
@section('title','Comissões')
@section('content')
@php
$isSales=auth()->user()->role==='sales';
$money=fn($cents)=>'R$ '.number_format(($cents ?? 0)/100,2,',','.');
$sum=fn($status)=>(int) data_get($totals,$status.'.amount',0);
$count=fn($status)=>(int) data_get($totals,$status.'.total',0);
$statusLabels=\App\Models\Commission::STATUSES;
// Qualidade: das vendas do período, quantas seguem em dia hoje.
$pct=fn($onTime,$sold)=>$sold ? (int) round($onTime*100/$sold) : null;
$tone=fn($p)=>$p===null ? 'badge-gray' : ($p>=90 ? 'badge-green' : ($p>=70 ? 'badge-amber' : 'badge-red'));
$planToday=['active'=>['Em dia','badge-green'],'suspended'=>['Em atraso','badge-red'],'cancelled'=>['Cancelou','badge-gray'],'ended'=>['Encerrado','badge-gray'],'awaiting_regularization'=>['Falta regularizar','badge-amber']];
$teamQuality=$pct($people->sum('on_time'),$people->sum('sold'));
$waiting=$count('awaiting_rate')+$count('held');
@endphp
<div class="page-heading"><div><h1>{{ $isSales ? 'Minhas comissões' : 'Comissões' }}</h1><p>Paga sobre a 1ª mensalidade confirmada. Se ela for estornada, a comissão volta.</p></div></div>

<div class="metrics-grid">
<div class="metric-card"><div class="metric-top"><span>A pagar</span><span class="metric-icon tone-ochre">@include('components.icon',['name'=>'wallet'])</span></div><div class="metric-value">{{ $money($sum('payable')) }}</div><span class="metric-detail"><span class="small-dot ochre"></span>{{ $count('payable') }} liberada(s){{ $waiting ? ' · '.$waiting.' aguardando regra' : '' }}</span></div>
<div class="metric-card"><div class="metric-top"><span>Pagas</span><span class="metric-icon tone-green">@include('components.icon',['name'=>'check'])</span></div><div class="metric-value">{{ $money($sum('paid')) }}</div><span class="metric-detail"><span class="small-dot green"></span>{{ $count('paid') }} paga(s)</span></div>
<div class="metric-card"><div class="metric-top"><span>Clientes em dia</span><span class="metric-icon tone-blue">@include('components.icon',['name'=>'heart'])</span></div><div class="metric-value">{{ $teamQuality===null ? '—' : $teamQuality.'%' }}</div><span class="metric-detail"><span class="small-dot blue"></span>{{ $people->sum('on_time') }} de {{ $people->sum('sold') }} vendas seguem pagando</span></div>
<div class="metric-card"><div class="metric-top"><span>Estornadas</span><span class="metric-icon tone-purple">@include('components.icon',['name'=>'refresh'])</span></div><div class="metric-value">{{ $money($sum('reversed')) }}</div><span class="metric-detail"><span class="small-dot purple"></span>{{ $count('reversed') }} por estorno ou cancelamento</span></div>
</div>

<form class="card commission-filters" method="GET" action="{{ route('commissions.index') }}">
<label class="field">De<input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></label>
<label class="field">Até<input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></label>
<label class="field">Situação<select name="status"><option value="">Todas</option>@foreach($statusLabels as $value=>$label)<option value="{{ $value }}" @selected(($filters['status'] ?? '')===$value)>{{ $label }}</option>@endforeach</select></label>
@unless($isSales)<label class="field">Quem recebe<select name="beneficiary"><option value="">Todos</option>@foreach($beneficiaries as $b)@php $key=$b->beneficiary_type==='unassigned'?'unassigned':(string)$b->beneficiary_user_id; @endphp<option value="{{ $key }}" @selected(($filters['beneficiary'] ?? '')===$key)>{{ $b->beneficiary_name }}</option>@endforeach</select></label>@endunless
@if($franchises->count()>1)<label class="field">Unidade<select name="franchise_id"><option value="">Todas</option>@foreach($franchises as $f)<option value="{{ $f->id }}" @selected((int)($filters['franchise_id'] ?? 0)===$f->id)>{{ $f->name }}</option>@endforeach</select></label>@endif
<div class="commission-filter-actions"><button class="button button-secondary" type="submit">@include('components.icon',['name'=>'search']) Filtrar</button>@if(array_filter($filters))<a class="text-link" href="{{ route('commissions.index') }}">Limpar</a>@endif</div>
</form>

@php $period='De '.\Illuminate\Support\Carbon::parse($filters['date_from'])->format('d/m').' a '.\Illuminate\Support\Carbon::parse($filters['date_to'])->format('d/m/Y'); @endphp
<section class="card section-gap"><div class="card-heading"><div><h2>{{ $isSales ? 'Meu resumo' : 'Por funcionário' }}</h2><p>{{ $period }}. Qualidade é quanto das vendas segue em dia hoje.</p></div></div>
<div class="table-scroll"><table><thead><tr><th>Funcionário</th><th>Vendas</th><th>Qualidade</th><th>A pagar</th><th>Pagas</th></tr></thead><tbody>
@forelse($people as $person)@php $q=$pct($person->on_time,$person->sold); @endphp<tr><td>@unless($isSales)<a href="{{ route('commissions.index',array_merge(array_filter($filters),['beneficiary'=>$person->beneficiary_user_id])) }}"><strong>{{ $person->name }}</strong></a>@else<strong>{{ $person->name }}</strong>@endunless @if($person->waiting)<small>{{ $person->waiting }} aguardando regra</small>@endif</td><td>{{ $person->sold }}</td><td><span class="badge {{ $tone($q) }}">{{ $q===null ? '—' : $q.'% em dia' }}</span><small>{{ collect([$person->late ? $person->late.' em atraso' : null, $person->left ? $person->left.($person->left===1 ? ' cancelou' : ' cancelaram') : null])->filter()->join(' · ') ?: 'Nenhum atraso ou cancelamento' }}</small></td><td>{{ $money($person->payable) }}</td><td>{{ $money($person->paid) }}</td></tr>
@empty<tr><td colspan="5"><div class="empty-state"><strong>Nenhuma venda com comissão no período.</strong></div></td></tr>@endforelse
@if($people->count()>1)<tr class="commission-total"><td><strong>Total</strong></td><td>{{ $people->sum('sold') }}</td><td><span class="badge {{ $tone($teamQuality) }}">{{ $teamQuality }}% em dia</span></td><td>{{ $money($people->sum('payable')) }}</td><td>{{ $money($people->sum('paid')) }}</td></tr>@endif
</tbody></table></div>
</section>

<section class="card section-gap"><div class="card-heading"><div><h2>Vendas do período</h2><p>Quem recebe, quanto e como o plano do cliente está hoje.@if($staff->isNotEmpty()) Em "Trocar", você muda quem fez a venda.@endif</p></div></div>
<div class="table-scroll"><table><thead><tr><th>Cliente</th><th>Quem recebe</th><th>Comissão</th><th>Plano hoje</th><th>Situação</th><th></th></tr></thead><tbody>
@forelse($rows as $c)
@php $plan=$planToday[data_get($c,'subscription.status')] ?? null; @endphp
<tr>
<td><a href="{{ route('proposals.show',$c->proposal_id) }}"><strong>{{ data_get($c,'subscription.customer.name','Tutor') }}</strong></a><small>{{ data_get($c,'subscription.pet.name') }} · {{ data_get($c,'productVersion.name') }}</small><small>1ª paga em {{ $c->earned_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}{{ $c->is_demo ? ' · demonstração' : '' }}</small></td>
<td><strong>{{ $c->beneficiary_name }}</strong>@if($c->rule!=='seller_proposal')<small>{{ $c->rule_label }}</small>@endif @if($staff->isNotEmpty() && $c->status!=='paid')<button class="text-link" type="button" data-dialog-open="seller-{{ $c->proposal_id }}">@include('components.bx',['name'=>'bx-edit-alt','size'=>14]) Trocar</button>@endif</td>
<td><strong>{{ $c->status==='not_applicable' ? 'Sem comissão' : ($c->calculation_type===null ? 'Sem regra' : $money($c->amount_cents)) }}</strong>@if($c->calculation_type==='percent')<small>{{ number_format($c->rate_basis_points/100,2,',','.') }}% de {{ $money($c->base_cents) }}</small>@elseif($c->calculation_type==='fixed')<small>Valor fixo</small>@endif</td>
<td>@if($plan)<span class="badge {{ $plan[1] }}">{{ $plan[0] }}</span>@else @include('components.status',['status'=>data_get($c,'subscription.status')]) @endif</td>
<td>@include('components.status',['status'=>$c->status,'statusLabel'=>$c->status_label])
@if($c->status==='paid')<small>Em {{ $c->paid_at->timezone('America/Sao_Paulo')->format('d/m/Y') }} · {{ $c->payout_reference }}</small>@endif
@if($c->status==='reversed')<small>{{ $c->reversal_reason }}</small>@if($c->clawback)<small class="text-danger">Já tinha sido paga: descontar de quem recebeu.</small>@endif @endif</td>
<td>@if($canPay && $c->status==='payable')<button class="button button-secondary button-small commission-pay" type="button" data-dialog-open="commission-pay-{{ $c->id }}">Marcar como paga</button>@endif</td>
</tr>
@empty
<tr><td colspan="6"><div class="empty-state">@include('components.icon',['name'=>'percent'])<strong>Nenhuma comissão por aqui.</strong><p>As comissões aparecem quando a 1ª mensalidade de uma venda é confirmada.</p></div></td></tr>
@endforelse
</tbody></table></div>
@if($rows->hasPages())<div class="pagination-wrapper">{{ $rows->links() }}</div>@endif
</section>

@if($canPay)
@foreach($rows->where('status','payable') as $c)
<dialog class="modal" id="commission-pay-{{ $c->id }}"><form method="POST" action="{{ route('commissions.pay',$c) }}">@csrf<div class="modal-heading"><h2>Registrar pagamento da comissão</h2><button type="button" class="icon-button" data-dialog-close aria-label="Fechar">@include('components.icon',['name'=>'close'])</button></div><p>{{ $c->beneficiary_name }} · {{ $money($c->amount_cents) }} · {{ data_get($c,'subscription.customer.name') }}</p><label class="field">Referência do pagamento ao beneficiário <span>*</span><input name="payout_reference" required minlength="3" maxlength="120" placeholder="Ex.: folha 10/2026, PIX 123"></label><div class="form-actions"><button type="button" class="button button-secondary" data-dialog-close>Voltar</button><button class="button button-primary" data-submit-label="Registrando...">Confirmar pagamento</button></div></form></dialog>
@endforeach
@endif
@if($staff->isNotEmpty())
@foreach($rows->where('status','!=','paid') as $c)
@include('proposals._seller-dialog',['proposalId'=>$c->proposal_id,'number'=>$c->origin_snapshot['proposal_number'] ?? '','current'=>$c->beneficiary_user_id ?? $c->sold_by_id,'staff'=>$staff])
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
@endsection

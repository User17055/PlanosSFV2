<details class="dashboard-disclosure" id="detalhes-financeiros">
<summary>Detalhes financeiros <span>Comissões e previsão mensal</span></summary>
<div class="dashboard-disclosure-body">
<div class="dashboard-financial-grid">
@foreach([
['Comissões pagas',$summary['commission_paid_cents']],
['Comissões a pagar',$summary['commission_payable_cents']],
['Recebido após comissões',$summary['net_after_commissions_cents']],
['Ticket médio da parcela',$summary['ticket_cents']],
['Saldo das faturas do mês',$insights['month_open_cents']],
['Recebido no mês',$insights['month_received_cents']],
] as [$label,$value])<div><small>{{ $label }}</small><strong>{{ $money($value) }}</strong></div>@endforeach
</div>
<div class="analytics-strip">@foreach([['active_count','Planos ativos',false],['customers','Tutores com plano ativo',false],['mrr_cents','Mensalidade ativa prevista',true],['cancelled_count','Cancelamentos',false],['pending_count','Propostas pendentes',false],['clawback_cents','Comissões a recuperar',true]] as [$key,$label,$currency])<div><small>{{ $label }}</small><strong>{{ $currency?$money($summary[$key]):$number($summary[$key]) }}</strong></div>@endforeach</div>
<details class="analytics-methodology"><summary>Cálculo dos valores</summary><div><p>Vendas: uma parcela por contratação aceita. Recebido: pagamentos conciliados, sem estornos. O saldo após comissões não desconta outras despesas.</p><p>A previsão considera as faturas emitidas com vencimento no mês da data final do filtro, exceto canceladas e estornadas. O recebido no mês segue a data do pagamento, inclusive de faturas de outros meses.</p></div></details>
</div>
</details>

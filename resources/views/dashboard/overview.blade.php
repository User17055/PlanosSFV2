@php
$months=$growth['months'];
$cancel=$growth['cancellations'];
$pct=fn($v)=>number_format($v,1,',','.').'%';
// Mini-gráfico do cartão: só a tendência, sem eixo; o número do cartão diz o valor.
$spark=function(array $values,string $color){
    $n=count($values); if($n<2) return '';
    $max=max($values); $min=min(0,min($values)); $span=max(1,$max-$min); $points=[];
    foreach(array_values($values) as $i=>$v) $points[]=round($i*120/($n-1),1).','.round(34-($v-$min)*30/$span,1);
    $line=implode(' ',$points);
    return '<svg class="dash-kpi-spark" viewBox="0 0 120 36" preserveAspectRatio="none" aria-hidden="true" focusable="false"><polygon points="0,36 '.$line.' 120,36" style="fill:var(--'.$color.')" fill-opacity=".14"/><polyline points="'.$line.'" style="stroke:var(--'.$color.')" fill="none" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"/></svg>';
};
$kpis=$general ? [
    ['Planos ativos',$number($summary['active_count']),'heart','c-main',$signed($growth['active_delta'],$number).' na carteira '.$growth['range_label'].($growth['active_growth']!==null?' ('.$signed($growth['active_growth'],fn($v)=>number_format($v,1,',','.')).'%)':''),$growth['active_delta']>=0?'is-up':'is-down',array_column($months,'active')],
    ['Receita mensal',$money($summary['mrr_cents']),'wallet','c-navy','Ticket médio de '.$money($summary['active_ticket_cents']),'',array_column($months,'mrr_cents')],
    ['Recebido no período',$money($summary['received_cents']),'check','c-main2',$money($growth['received_cents']).' '.$growth['range_label'],'',array_column($months,'received_cents')],
    ['Cancelamentos',$number($summary['cancelled_count']),'close','c-out',$summary['cancelled_lost_cents']?$money($summary['cancelled_lost_cents']).' por mês a menos':'nenhum no período','',array_column($months,'left')],
] : [
    ['Contratos fechados',$number($summary['sold_count']),'file','c-main',$money($summary['sold_cents']).' em mensalidades vendidas','',array_column($months,'entered')],
    ['Planos ativos',$number($summary['active_count']),'heart','c-navy',$signed($growth['active_delta'],$number).' '.$growth['range_label'],$growth['active_delta']>=0?'is-up':'is-down',array_column($months,'active')],
    ['Recebido',$money($summary['received_cents']),'check','c-main2',$money($growth['received_cents']).' '.$growth['range_label'],'',array_column($months,'received_cents')],
    ['Cancelamentos',$number($summary['cancelled_count']),'close','c-out',$summary['cancelled_lost_cents']?$money($summary['cancelled_lost_cents']).' por mês a menos':'nenhum no período','',array_column($months,'left')],
];
$mix=$plans->where('active','>',0)->values();
$mixTotal=max(1,$mix->sum('active'));
$slices=$mix->take(5)->map(fn($p)=>['name'=>$p['name'],'value'=>$p['active'],'color'=>$p['slot']===null?'var(--c-other)':'var(--slot-'.$p['slot'].')'])->all();
if($mix->count()>5) $slices[]=['name'=>'Outros planos','value'=>$mix->slice(5)->sum('active'),'color'=>'var(--c-other)'];
// Rosca: cada fatia é um arco do mesmo círculo, com 2px de folga entre elas.
$donut=function(array $slices,float $total){
    $r=52; $c=2*M_PI*$r; $offset=0; $out='';
    foreach($slices as $s){ $len=$s['value']/$total*$c; $dash=max(0.01,$len-($len>4?2:0));
        $out.='<circle r="'.$r.'" cx="64" cy="64" fill="none" stroke-width="20" style="stroke:'.$s['color'].'" stroke-dasharray="'.round($dash,2).' '.round($c,2).'" stroke-dashoffset="'.round(-$offset,2).'"><title>'.e($s['name']).': '.number_format($s['value'],0,',','.').'</title></circle>';
        $offset+=$len; }
    return $out;
};
$health=['Em dia'=>[$summary['active_count'],'c-ok','check'],'Em atraso'=>[$summary['suspended_count'],'c-late','alert'],'Aguardando pagamento'=>[$summary['awaiting_count'],'c-wait','clock']];
$healthTotal=max(1,array_sum(array_column($health,0)));
$speciesTotal=max(1,$insights['species']['dog']+$insights['species']['cat']);
$maxPlanLoss=max(1,collect($cancel['plans'])->max('count'));
$maxTenure=max(1,max($cancel['tenure']));
@endphp
<div class="dash-v2">
<div class="dash-kpis" id="resumo">
@foreach($kpis as [$label,$value,$icon,$color,$sub,$trend,$series])
<article class="dash-kpi" style="--kpi:var(--{{ $color }})"><div class="dash-kpi-top"><span class="dash-kpi-icon">@include('components.icon',['name'=>$icon])</span><span class="dash-kpi-label">{{ $label }}</span></div><strong class="dash-kpi-value">{{ $value }}</strong><small class="dash-kpi-sub {{ $trend }}">{{ $sub }}</small>{!! $spark($series,$color) !!}</article>
@endforeach
</div>
@if($general && !empty($summary['package_active_count']))<p class="dash-simple-note dash-packages-aside">Pacotes de banho (só exibição, fora dos números acima): <strong>{{ $number($summary['package_active_count']) }} ativos · {{ $money($summary['package_mrr_cents']) }} por mês</strong></p>@endif

<section class="card dash-panel" id="crescimento" aria-labelledby="dash-growth-title">
<div class="dash-panel-head"><div><h2 id="dash-growth-title">Crescimento dos {{ $general?'planos gerais':'planos novos' }}</h2><p>Planos na carteira (em dia e em atraso) no fim de cada mês, de {{ $months[0]['month'] }} a {{ end($months)['month'] }}.</p></div>
<dl class="dash-stats"><div><dt>Na carteira</dt><dd>{{ $number(end($months)['active']) }}</dd><small class="{{ $growth['active_delta']>=0?'is-up':'is-down' }}">{{ $signed($growth['active_delta'],$number) }}@if($growth['active_growth']!==null) ({{ $signed($growth['active_growth'],fn($v)=>number_format($v,1,',','.')) }}%)@endif</small></div><div><dt>Receita da carteira</dt><dd>{{ $money(end($months)['mrr_cents']) }}</dd><small class="{{ $growth['mrr_delta_cents']>=0?'is-up':'is-down' }}">{{ $signed($growth['mrr_delta_cents'],$money) }}</small></div><div><dt>Entraram</dt><dd>{{ $number($growth['entered']) }}</dd><small>{{ $growth['range_label'] }}</small></div><div><dt>Saíram</dt><dd>{{ $number($growth['left']) }}</dd><small>{{ $growth['range_label'] }}</small></div></dl></div>
<div class="dash-chart dash-chart-tall" data-chart="growth" role="img" aria-label="Planos na carteira no fim de cada mês: {{ collect($months)->map(fn($m)=>$m['month'].' '.$m['active'])->implode(', ') }}"></div>
<div class="dash-subchart"><div class="dash-subchart-head"><h3>Entraram e saíram por mês</h3><ul class="dash-legend"><li style="--dot:var(--c-in)">Entraram</li><li style="--dot:var(--c-out)">Saíram</li></ul></div>
<div class="dash-chart" data-chart="flow" role="img" aria-label="Planos que entraram e saíram por mês: {{ collect($months)->map(fn($m)=>$m['month'].' +'.$m['entered'].' −'.$m['left'])->implode(', ') }}"></div></div>
<details class="dash-growth-table"><summary>Ver mês a mês</summary><div class="table-scroll"><table><thead><tr><th>Mês</th><th>Na carteira</th><th>Entraram</th><th>Saíram</th><th>Taxa de cancelamento</th><th>Receita da carteira</th><th>Recebido</th></tr></thead><tbody>@foreach(array_reverse($months) as $m)<tr><td>{{ $m['month'] }}</td><td>{{ $number($m['active']) }}</td><td>{{ $number($m['entered']) }}</td><td>{{ $number($m['left']) }}</td><td>{{ $m['churn']!==null?$pct($m['churn']):'—' }}</td><td>{{ $money($m['mrr_cents']) }}</td><td>{{ $money($m['received_cents']) }}</td></tr>@endforeach</tbody></table></div></details>
@if($general)<p class="dash-panel-note">{{ $growth['with_vindi']?'Inclui quem cancelou na Vindi, antes ou depois da importação. Cancelamento de quem seguiu com outra assinatura do mesmo tipo (duplicada, campanha, troca de cartão) não conta como entrada nem saída.':'A base antiga entra pela data de início de cada plano.' }}</p>@endif
</section>

<div class="dash-row">
<section class="card dash-panel" aria-labelledby="dash-received-title"><div class="dash-panel-head"><div><h2 id="dash-received-title">Recebido por mês</h2><p>Mensalidades pagas e conferidas na Vindi.</p></div><dl class="dash-stats dash-stats-one"><div><dt>{{ ucfirst($growth['range_label']) }}</dt><dd>{{ $money($growth['received_cents']) }}</dd></div></dl></div>
<div class="dash-chart" data-chart="received" role="img" aria-label="Recebido por mês: {{ collect($months)->map(fn($m)=>$m['month'].' '.$money($m['received_cents']))->implode(', ') }}"></div></section>
<section class="card dash-panel" aria-labelledby="dash-mix-title"><div class="dash-panel-head"><div><h2 id="dash-mix-title">Planos ativos por plano</h2><p>Quanto cada plano pesa na carteira.</p></div></div>
@if($mix->isEmpty())<div class="analytics-empty">Nenhum plano ativo.</div>@else
<div class="dash-donut"><svg viewBox="0 0 128 128" role="img" aria-label="Planos ativos por plano: {{ collect($slices)->map(fn($s)=>$s['name'].' '.$s['value'])->implode(', ') }}"><g transform="rotate(-90 64 64)">{!! $donut($slices,$mixTotal) !!}</g><text x="64" y="62" text-anchor="middle" class="dash-donut-total">{{ $number($mix->sum('active')) }}</text><text x="64" y="80" text-anchor="middle" class="dash-donut-caption">ativos</text></svg>
<ul class="dash-donut-legend">@foreach($slices as $s)<li style="--dot:{{ $s['color'] }}"><span>{{ $s['name'] }}</span><b>{{ $number($s['value']) }}</b><small>{{ $pct($s['value']*100/$mixTotal) }}</small></li>@endforeach</ul></div>@endif
</section>
</div>

<section class="card dash-panel dash-cancel" id="cancelamentos" aria-labelledby="dash-cancel-title">
<div class="dash-panel-head"><div><h2 id="dash-cancel-title">Cancelamentos</h2><p>Quem saiu de {{ $months[0]['month'] }} a {{ end($months)['month'] }}.</p></div>
<dl class="dash-stats"><div><dt>Saíram</dt><dd>{{ $number($cancel['count']) }}</dd><small>{{ $growth['range_label'] }}</small></div><div><dt>Receita perdida</dt><dd>{{ $money($cancel['lost_cents']) }}</dd><small>por mês</small></div><div><dt>Taxa média</dt><dd>{{ $cancel['churn']!==null?$pct($cancel['churn']):'—' }}</dd><small>da carteira por mês</small></div>@if($cancel['replaced'])<div><dt>Continuaram</dt><dd>{{ $number($cancel['replaced']) }}</dd><small>com outra assinatura</small></div>@endif</dl></div>
@if($cancel['count'])
<div class="dash-chart" data-chart="cancel" role="img" aria-label="Cancelamentos por mês: {{ collect($months)->map(fn($m)=>$m['month'].' '.$m['left'])->implode(', ') }}"></div>
<div class="dash-row dash-row-inner">
<div><h3>Planos que mais perderam</h3>@foreach($cancel['plans'] as $p)<div class="dash-hbar" style="--bar:var(--c-out)"><div class="dash-hbar-label"><span>{{ $p['name'] }}</span><b>{{ $number($p['count']) }}</b></div><div class="dash-hbar-track"><i style="width:{{ max(3,$p['count']*100/$maxPlanLoss) }}%"></i></div><small>{{ $money($p['lost_cents']) }} por mês a menos</small></div>@endforeach</div>
<div><h3>Quanto tempo ficaram</h3>@foreach($cancel['tenure'] as $label=>$count)<div class="dash-hbar" style="--bar:var(--c-in)"><div class="dash-hbar-label"><span>{{ $label }}</span><b>{{ $number($count) }}</b></div><div class="dash-hbar-track"><i style="width:{{ $count?max(3,$count*100/$maxTenure):0 }}%"></i></div><small>{{ $pct($count*100/max(1,$cancel['count'])) }} de quem saiu</small></div>@endforeach</div>
</div>
@else<div class="analytics-empty">Nenhum cancelamento de {{ $months[0]['month'] }} a {{ end($months)['month'] }}.</div>@endif
</section>

<div class="dash-row">
<section class="card dash-panel" aria-labelledby="dash-health-title"><div class="dash-panel-head"><div><h2 id="dash-health-title">Situação da carteira</h2><p>Planos em dia e com mensalidade em atraso há mais de 5 dias.</p></div></div>
<div class="dash-stack" role="img" aria-label="{{ collect($health)->map(fn($h,$label)=>$label.' '.$h[0])->implode(', ') }}">@foreach($health as $label=>[$count,$tone])@if($count)<i style="width:{{ $count*100/$healthTotal }}%;background:var(--{{ $tone }})" title="{{ $label }}: {{ $number($count) }}"></i>@endif @endforeach</div>
<ul class="dash-health">@foreach($health as $label=>[$count,$tone,$icon])<li style="--dot:var(--{{ $tone }})">@include('components.icon',['name'=>$icon])<span>{{ $label }}</span><b>{{ $number($count) }}</b><small>{{ $pct($count*100/$healthTotal) }}</small></li>@endforeach</ul>
@if($summary['overdue_cents'])<p class="dash-panel-note">Em atraso: <strong>{{ $money($summary['overdue_cents']) }}</strong>@if($summary['open_cents']) · A receber no período: <strong>{{ $money($summary['open_cents']) }}</strong>@endif</p>@endif
</section>
<section class="card dash-panel" aria-labelledby="dash-species-title"><div class="dash-panel-head"><div><h2 id="dash-species-title">Cães e gatos</h2><p>Pets com plano de saúde ativo.</p></div></div>
<div class="dash-donut dash-donut-small"><svg viewBox="0 0 128 128" role="img" aria-label="Cães com plano ativo: {{ $insights['species']['dog'] }}. Gatos com plano ativo: {{ $insights['species']['cat'] }}."><g transform="rotate(-90 64 64)">{!! $donut([['name'=>'Cães','value'=>$insights['species']['dog'],'color'=>'var(--c-main)'],['name'=>'Gatos','value'=>$insights['species']['cat'],'color'=>'var(--c-gray)']],$speciesTotal) !!}</g><text x="64" y="62" text-anchor="middle" class="dash-donut-total">{{ $number($insights['species']['dog']+$insights['species']['cat']) }}</text><text x="64" y="80" text-anchor="middle" class="dash-donut-caption">pets</text></svg>
<ul class="dash-donut-legend"><li style="--dot:var(--c-main)"><span>Cães com plano ativo</span><b>{{ $number($insights['species']['dog']) }}</b><small>{{ $pct($insights['species']['dog']*100/$speciesTotal) }}</small></li><li style="--dot:var(--c-gray)"><span>Gatos com plano ativo</span><b>{{ $number($insights['species']['cat']) }}</b><small>{{ $pct($insights['species']['cat']*100/$speciesTotal) }}</small></li></ul></div>
</section>
</div>
</div>

@if($rule->coverage_type==='discount')
<small>{{ str_starts_with($rule->name,'Até ')?'Percentual conforme contrato':number_format(($rule->discount_basis_points ?? 0)/100,0,',','.').'% de desconto' }}</small>
@else
<small>{{ $rule->quantity_limit ?? 'Sem limite numérico informado' }} por {{ ['calendar_month'=>'mês-calendário','monthly_cycle'=>'ciclo mensal','contract_year'=>'ano contratual','lifetime'=>'vigência'][$rule->period_type] ?? $rule->period_type }}</small>
@endif
@if($rule->description)<small>{{ $rule->description }}</small>@endif
<small>{{ $rule->requires_authorization?'Aplicação conforme condições do contrato':$rule->waiting_days.' dias de carência' }}</small>

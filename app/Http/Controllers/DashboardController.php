<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\AccessService;
use App\Services\Reports\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard, AccessService $access)
    {
        $user=$request->user();
        $filters=$dashboard->filters($user,$request->query());
        $recentLeads=in_array($user->role,['central_admin','manager','sales','operator'],true) ? $access->scope(Lead::where('status','new'),$user)->latest('id')->limit(3)->get() : collect();
        return response()->view('dashboard',$dashboard->report($user,$filters)+['recentLeads'=>$recentLeads])->header('Cache-Control','private, no-store');
    }

    public function export(Request $request,DashboardService $dashboard)
    {
        $filters=$dashboard->filters($request->user(),$request->query());
        $report=$dashboard->report($request->user(),$filters);
        \App\Models\AuditLog::create(['actor_id'=>$request->user()->id,'action'=>'dashboard.exported','entity_type'=>'dashboard','entity_id'=>$request->user()->id,'data'=>['filters'=>$filters]]);
        return response()->streamDownload(function()use($report){
            $stream=fopen('php://output','wb'); fwrite($stream,"\xEF\xBB\xBF");
            $write=function(array $row)use($stream){fputcsv($stream,array_map(fn($v)=>app(\App\Services\Reports\ReportService::class)->csvSafe($v),$row),';','"','');};
            $write(['Dashboard de resultados','De',$report['filters']['date_from'],'Até',$report['filters']['date_to'],'Base',$report['filters']['data_mode']]);
            $write(['Indicador','Valor']);
            $labels=['sold_count'=>'Contratações aceitas','sold_cents'=>'Parcelas vendidas (R$)','received_cents'=>'Recebido conciliado (R$)','billed_cents'=>'Faturado com vencimento no período (R$)','open_cents'=>'Saldo a receber (R$)','overdue_cents'=>'Em atraso (R$)','commission_payable_cents'=>'Comissões do período a pagar (R$)','commission_paid_cents'=>'Comissões pagas no período (R$)','commission_held_count'=>'Comissões aguardando regra ou liberação','clawback_cents'=>'Comissões estornadas a recuperar (R$)','active_count'=>'Planos ativos hoje','customers'=>'Tutores com plano ativo','mrr_cents'=>'Mensalidades ativas previstas (R$)','proposals_count'=>'Propostas criadas','pending_count'=>'Propostas pendentes','cancelled_count'=>'Cancelamentos no período','ticket_cents'=>'Ticket da parcela vendida (R$)','net_after_commissions_cents'=>'Recebido após comissões (R$)','conversion'=>'Conversão em aceite (%)','cohort_accepted'=>'Aceites das propostas do período','cohort_documents'=>'Contratos válidos das propostas do período','cohort_initial_paid'=>'Primeiros pagamentos das propostas do período'];
            foreach($report['summary'] as $key=>$value) $write([$labels[$key]??$key,str_ends_with($key,'_cents')?number_format($value/100,2,',',''):$value]);
            $insights=$report['insights'];
            $write(['Cães com plano ativo',$insights['species']['dog']]);$write(['Gatos com plano ativo',$insights['species']['cat']]);
            $write(['Plano(s) mais vendido(s)',implode(' / ',$insights['best_sellers']),'Contratações por produto',$insights['best_sales']]);
            $write(['Mês da previsão',$insights['month_label'],'Faturas emitidas (R$)',number_format($insights['month_expected_cents']/100,2,',','')]);
            $write(['Saldo dessas faturas (R$)',number_format($insights['month_open_cents']/100,2,',',''),'Recebido no mês (R$)',number_format($insights['month_received_cents']/100,2,',','')]);
            $write([]);$write(['Produto','Vendas','Parcelas vendidas (R$)','Recebido no período (R$)','Previsto no mês (R$)','Ativos hoje','Cancelados no período']);
            foreach($report['plans'] as $row)$write([$row['name'],$row['sales'],number_format($row['sold_cents']/100,2,',',''),number_format($row['received_cents']/100,2,',',''),number_format($row['month_expected_cents']/100,2,',',''),$row['active'],$row['cancelled']]);
            $write([]);$write(['Origem','Contratações','Tutores que contrataram','Recebido (R$)']);
            foreach($insights['origins'] as $row)$write([$row['label'],$row['sales'],$row['customers'],number_format($row['received_cents']/100,2,',','')]);
            $write([]);$write(['Quem recomendou','Tipo','Contratações','Tutores que contrataram','Recebido (R$)']);
            foreach($insights['referrals'] as $row)$write([$row['name'],$row['registered']?'Tutor cadastrado':'Nome informado',$row['sales'],$row['customers'],number_format($row['received_cents']/100,2,',','')]);
            $write([]); $write(['Mês','Parcelas vendidas (R$)','Recebido (R$)','Comissões pagas (R$)']);
            foreach($report['series'] as $row)$write([$row['month'],number_format($row['sold_cents']/100,2,',',''),number_format($row['received_cents']/100,2,',',''),number_format($row['commission_cents']/100,2,',','')]);
            $write([]); $write(['UF','Cidade','Bairro','Tutores ativos hoje','Planos ativos hoje']);
            foreach($report['geo']['neighborhoods'] as $row)$write([$row['state'],$row['city'],$row['name'],$row['customers'],$row['plans']]);
            fclose($stream);
        },'dashboard-'.$filters['date_from'].'-a-'.$filters['date_to'].'.csv',['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
}

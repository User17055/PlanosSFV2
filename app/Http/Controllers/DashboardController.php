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
        $recentLeads=in_array($user->role,['central_admin','manager','sales','operator'],true) && $user->canOpen('leads') ? $access->scope(Lead::visibleTo($user)->where('status','new')->where('source','!=','teste'),$user)->latest('id')->limit(3)->get() : collect();
        return response()->view('dashboard',$dashboard->report($user,$filters)+['recentLeads'=>$recentLeads,'simplesvetPending'=>$this->simplesvetPending($user,$access),'legacyPending'=>in_array($user->role,['central_admin','manager'],true) && $user->canUse('customers.update')?\App\Models\LegacyPendingItem::whereNull('resolved_at')->count():0,'skippedBills'=>$this->skippedBills($user,$access)])->header('Cache-Control','private, no-store');
    }

    /** Meses em que a Vindi não gerou a fatura (ver VindiReconciler::flagSkippedMonths), dos contratos das unidades de quem vê. */
    private function skippedBills($user, AccessService $access)
    {
        if (! in_array($user->role,['central_admin','manager','finance'],true)) return collect();
        $events=\App\Models\IntegrationEvent::where('event_type','bill_skipped')->where('status','manual')->where('received_at','>=',now()->subMonths(3))->latest('id')->get(['entity_id','payload']);
        $subscriptions=$access->scope(\App\Models\Subscription::with('customer:id,name')->whereIn('id',$events->pluck('entity_id')),$user)->get()->keyBy('id');
        return $events->filter(fn($event)=>$subscriptions->has($event->entity_id))->map(fn($event)=>['subscription'=>$subscriptions[$event->entity_id],'vindi'=>$event->payload['vindi_subscription_id']??'',
            'month'=>\Carbon\Carbon::parse(($event->payload['month']??'').'-01')->locale('pt_BR')->translatedFormat('F/Y')])->values();
    }

    /** Onde o robô do SimplesVet parou (conferência de gente), só dos contratos das unidades de quem vê. */
    private function simplesvetPending($user, AccessService $access)
    {
        if (! app(\App\Services\Integrations\SimplesVetAdapter::class)->enabled()) return collect(); // robô desligado: sem quadro de pendências
        $events=\App\Models\IntegrationEvent::where('provider','simplesvet')->where('entity_type','subscription')->whereIn('status',['manual','error'])
            ->latest('id')->get(['entity_id','error'])->unique('entity_id');
        $subscriptions=$access->scope(\App\Models\Subscription::with(['customer:id,name','pet:id,name'])->whereIn('id',$events->pluck('entity_id')),$user)->get()->keyBy('id');
        return $events->filter(fn($event)=>$subscriptions->has($event->entity_id))
            ->map(fn($event)=>['subscription'=>$subscriptions[$event->entity_id],'reason'=>preg_replace('/^SimplesVet:\s*/','',(string)$event->error)])->values();
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
            $labels=['sold_count'=>'Contratos fechados','sold_cents'=>'Mensalidade dos contratos fechados (R$)','received_cents'=>'Recebido conciliado (R$)','billed_cents'=>'Faturado com vencimento no período (R$)','open_cents'=>'Saldo a receber (R$)','overdue_cents'=>'Em atraso (R$)','commission_payable_cents'=>'Comissões do período a pagar (R$)','commission_paid_cents'=>'Comissões pagas no período (R$)','commission_held_count'=>'Comissões aguardando regra ou liberação','clawback_cents'=>'Comissões estornadas a recuperar (R$)','active_count'=>'Planos ativos hoje','customers'=>'Tutores com plano ativo','mrr_cents'=>'Mensalidades ativas previstas (R$)','proposals_count'=>'Propostas oferecidas','pending_count'=>'Propostas pendentes','cancelled_count'=>'Cancelamentos no período','ticket_cents'=>'Mensalidade média por contrato (R$)','net_after_commissions_cents'=>'Recebido após comissões (R$)','conversion'=>'Conversão em aceite (%)','cohort_accepted'=>'Aceites das propostas do período','cohort_documents'=>'Contratos válidos das propostas do período','cohort_initial_paid'=>'Primeiros pagamentos das propostas do período'];
            foreach($report['summary'] as $key=>$value) $write([$labels[$key]??$key,str_ends_with($key,'_cents')?number_format($value/100,2,',',''):$value]);
            $insights=$report['insights'];
            $write(['Cães com plano ativo',$insights['species']['dog']]);$write(['Gatos com plano ativo',$insights['species']['cat']]);
            $write(['Plano(s) mais vendido(s)',implode(' / ',$insights['best_sellers']),'Contratações por produto',$insights['best_sales']]);
            $write(['Mês da previsão',$insights['month_label'],'Faturas emitidas (R$)',number_format($insights['month_expected_cents']/100,2,',','')]);
            $write(['Saldo dessas faturas (R$)',number_format($insights['month_open_cents']/100,2,',',''),'Recebido no mês (R$)',number_format($insights['month_received_cents']/100,2,',','')]);
            $write([]);$write(['Produto','Vendas','Mensalidade dos contratos fechados (R$)','Recebido no período (R$)','Previsto no mês (R$)','Ativos hoje','Cancelados no período']);
            foreach($report['plans'] as $row)$write([$row['name'],$row['sales'],number_format($row['sold_cents']/100,2,',',''),number_format($row['received_cents']/100,2,',',''),number_format($row['month_expected_cents']/100,2,',',''),$row['active'],$row['cancelled']]);
            $write([]);$write(['Origem','Contratações','Tutores que contrataram','Recebido (R$)']);
            foreach($insights['origins'] as $row)$write([$row['label'],$row['sales'],$row['customers'],number_format($row['received_cents']/100,2,',','')]);
            $write([]);$write(['Quem recomendou','Tipo','Contratações','Tutores que contrataram','Recebido (R$)']);
            foreach($insights['referrals'] as $row)$write([$row['name'],$row['registered']?'Tutor cadastrado':'Nome informado',$row['sales'],$row['customers'],number_format($row['received_cents']/100,2,',','')]);
            $write([]); $write(['Mês','Mensalidade dos contratos fechados (R$)','Recebido (R$)','Comissões pagas (R$)']);
            foreach($report['series'] as $row)$write([$row['month'],number_format($row['sold_cents']/100,2,',',''),number_format($row['received_cents']/100,2,',',''),number_format($row['commission_cents']/100,2,',','')]);
            $write([]); $write(['UF','Cidade','Bairro','Tutores ativos hoje','Planos ativos hoje']);
            foreach($report['geo']['neighborhoods'] as $row)$write([$row['state'],$row['city'],$row['name'],$row['customers'],$row['plans']]);
            fclose($stream);
        },'dashboard-'.$filters['date_from'].'-a-'.$filters['date_to'].'.csv',['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
}

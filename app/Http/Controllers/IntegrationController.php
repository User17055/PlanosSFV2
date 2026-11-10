<?php

namespace App\Http\Controllers;

use App\Jobs\{ProcessVindiWebhookJob, SyncPartnerSubscriptionJob};
use App\Models\{AuditLog, IntegrationEvent, Subscription};
use App\Services\AccessService;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    public function index()
    {
        $events=IntegrationEvent::latest()->paginate(20);
        $auditLogs=AuditLog::with('actor')->latest()->take(12)->get();
        $providers=[
            'vindi'=>['name'=>'Vindi','status'=>config('integrations.vindi.enabled')?'configured':'pending','description'=>'Cobrança e conciliação financeira. Homologação e credenciais necessárias.'],
            'simplesvet'=>['name'=>'SimplesVet','status'=>app(\App\Services\Integrations\SimplesVetAdapter::class)->enabled()?'configured':'pending',
                'description'=>app(\App\Services\Integrations\SimplesVetAdapter::class)->enabled()?'Robô com conta própria: no plano ativo põe "CLIENTE PLANO" no tutor e o plano com a data no pet (cadastra o que faltar); no fim do plano tira as marcas.':'Robô desligado ou sem a conta configurada; tarefas ficam manuais.'],
            'tudoprapet'=>['name'=>'TudoPraPet','status'=>app(\App\Services\Integrations\TudoPraPetClient::class)->enabled()?'configured':'pending',
                'description'=>app(\App\Services\Integrations\TudoPraPetClient::class)->enabled()?'Grupo "Plano São Francisco": entra na ativação, sai no fim do plano ou no atraso após o fim do mês; conferência diária.':'Chave da API do Plano São Francisco ainda não configurada; tarefas ficam manuais.'],
        ];
        return view('integrations.index',compact('events','providers','auditLogs'));
    }

    /** "Tentar de novo" do quadro de pendências do SimplesVet, depois que a equipe resolveu o motivo. */
    public function retrySimplesVet(Request $request,\App\Models\Subscription $subscription,\App\Services\AccessService $access)
    {
        $access->franchise($request->user(),(int)$subscription->originating_franchise_id);
        IntegrationEvent::where('provider','simplesvet')->where('entity_type','subscription')->where('entity_id',$subscription->id)
            ->whereIn('status',['manual','error'])->update(['status'=>'pending','error'=>null]);
        \App\Jobs\SyncSimplesVetJob::dispatch($subscription->id);
        return back()->with('success','O robô vai tentar de novo em instantes. Se der certo, o aviso some; se não, volta com o motivo.');
    }

    public function retry(Request $request,IntegrationEvent $event)
    {
        abort_if($event->status==='synced' || $event->status==='processed',409,'Evento já concluído.');
        abort_unless(in_array($event->provider,['vindi','simplesvet','tudoprapet'],true),422,'Destino de integração não suportado.');
        if ($event->provider==='vindi') abort_unless(str_starts_with($event->event_type,'bill_') || $event->entity_type==='local_subscription',422,'Solicitação financeira requer intervenção e conciliação antes de reprocessar.');
        $event->update(['status'=>'pending','error'=>null]);
        if($event->provider==='vindi') ProcessVindiWebhookJob::dispatch($event->id);
        else SyncPartnerSubscriptionJob::dispatch($event->id);
        AuditLog::create(['actor_id'=>$request->user()->id,'action'=>'integration.retry','entity_type'=>'integration_event','entity_id'=>$event->id]);
        return back()->with('success','Reprocessamento solicitado. O resultado aparecerá na fila de integrações.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\{Customer, Invoice, Pet, Subscription};
use App\Services\Integrations\VindiCheckoutService;
use App\Services\Domain\{EligibilityService, FinancialService};
use Illuminate\Http\Request;

class PortalController extends Controller
{
    // Impedimentos que valem para o tutor inteiro: aparecem uma vez no topo, não em cada contratação.
    private const CUSTOMER_WIDE = ['CUSTOMER_OVERDUE','FINANCIAL_VERIFICATION_PENDING'];

    private const TUTOR_MESSAGES = [
        'CONTRACT_PENDING'=>'O contrato está em conferência pela unidade.',
        'FIRST_PAYMENT_PENDING'=>'Aguardando a confirmação do primeiro pagamento.',
        'SUBSCRIPTION_NOT_ACTIVE'=>'A contratação ainda não foi ativada para atendimento.',
        'SUBSCRIPTION_EXPIRED'=>'A vigência contratada terminou.',
        'SLOT_NOT_OWNED'=>'A unidade está concluindo a ativação desta contratação.',
        'REGISTRATION_INCOMPLETE'=>'Complete o cadastro do tutor e do pet para liberar os benefícios.',
        'PET_AGE_INELIGIBLE'=>'A idade do pet precisa ser revisada pela unidade.',
        'SPECIES_NOT_COVERED'=>'A espécie do pet precisa ser revisada pela unidade.',
    ];

    public function __invoke(Request $request,EligibilityService $eligibility,FinancialService $financial)
    {
        if (!$request->user()->email_verified_at) return view('auth.verify');
        return view('portal',$this->careData($request,$eligibility,$financial));
    }

    /** Página do pet: o plano contratado, benefícios, contrato e histórico daquele pet. */
    public function pet(Request $request,Pet $pet,EligibilityService $eligibility,FinancialService $financial)
    {
        if (!$request->user()->email_verified_at) return view('auth.verify');
        abort_unless($pet->customer_id===$request->user()->customer_id,404);
        $data=$this->careData($request,$eligibility,$financial);
        return view('portal.pet',$data+['pet'=>$data['customer']->pets->firstWhere('id',$pet->id)]);
    }

    /** Página de um plano do pet: saldo dos benefícios, histórico de uso, contrato e demais planos do pet. */
    public function plan(Request $request,Pet $pet,Subscription $subscription,EligibilityService $eligibility,FinancialService $financial)
    {
        if (!$request->user()->email_verified_at) return view('auth.verify');
        abort_unless($pet->customer_id===$request->user()->customer_id && $subscription->pet_id===$pet->id,404);
        $data=$this->careData($request,$eligibility,$financial);
        return view('portal.plan',$data+['pet'=>$data['customer']->pets->firstWhere('id',$pet->id),'subscription'=>$data['customer']->subscriptions->firstWhere('id',$subscription->id)]);
    }

    private function careData(Request $request,EligibilityService $eligibility,FinancialService $financial): array
    {
        $money=fn($cents)=>'R$ '.number_format($cents/100,2,',','.');
        $date=fn($value)=>$value?->timezone('America/Sao_Paulo')->format('d/m/Y');
        $ageLabel=function($pet){
            $months=$pet->birth_date?max(0,(int)$pet->birth_date->diffInMonths(now())):$pet->estimated_age_months;
            if($months===null) return null;
            $label=$months<12?$months.($months===1?' mês':' meses'):intdiv($months,12).(intdiv($months,12)===1?' ano':' anos');
            return $pet->birth_date?$label:'cerca de '.$label;
        };
        $customer=Customer::with(['pets'=>fn($q)=>$q->orderBy('name'),'subscriptions'=>fn($q)=>$q->orderByRaw("category='health_plan' desc")->latest('id'),'subscriptions.pet','subscriptions.originatingFranchise','subscriptions.productVersion.product','subscriptions.productVersion.benefitRules','subscriptions.contractDocuments','subscriptions.cancellationRequest','subscriptions.proposal',
            'subscriptions.usages'=>fn($q)=>$q->where('status','consumed')->with(['benefitRule','servicingFranchise','reversals'])->latest('consumed_at'),
            'proposals'=>fn($q)=>$q->latest('id'),'proposals.productVersion','proposals.pet','invoices.subscription.pet','invoices.subscription.productVersion'])->findOrFail($request->user()->customer_id);
        $financialState=$financial->customerStatus($customer);
        $activeSubscriptions=$customer->subscriptions->reject(fn($subscription)=>$subscription->isTerminal());
        // Pets com cuidado vigente primeiro; a ordem alfabética vale dentro de cada grupo.
        $customer->setRelation('pets',$customer->pets->sortBy(fn($pet)=>$activeSubscriptions->contains('pet_id',$pet->id)?0:1)->values());
        $benefits=[];$notices=[];
        foreach($activeSubscriptions as $subscription) {
            $result=$eligibility->subscription($subscription);
            $notices[$subscription->id]=$this->notices($subscription,$result);
            foreach($subscription->productVersion->benefitRules as $rule) {
                $raw=$eligibility->benefit($subscription,$rule);
                $benefits[$subscription->id][$rule->id]=$this->benefitState($rule,$raw,array_column($result['impediments'],'code'))+['remaining'=>data_get($raw,'remaining'),'periodEnd'=>data_get($raw,'period_end')];
            }
        }
        [$openInvoices,$pastInvoices]=$this->splitInvoices($customer);
        $pendingProposals=$customer->proposals->filter(fn($proposal)=>in_array($proposal->status,['draft','awaiting_acceptance'],true) && !$proposal->locked_at && $proposal->expires_at?->isFuture())->values();
        $unit=$activeSubscriptions->pluck('originatingFranchise')->filter()->first(fn($franchise)=>$franchise->code!=='CENTRAL');
        return compact('customer','financialState','benefits','notices','openInvoices','pastInvoices','pendingProposals','activeSubscriptions','unit','money','date','ageLabel');
    }

    public function invoices(Request $request,FinancialService $financial)
    {
        if (!$request->user()->email_verified_at) return view('auth.verify');
        $customer=Customer::with(['invoices.subscription.pet','invoices.subscription.productVersion'])->findOrFail($request->user()->customer_id);
        [$openInvoices,$pastInvoices]=$this->splitInvoices($customer);
        return view('portal.faturas',['financialState'=>$financial->customerStatus($customer),'openInvoices'=>$openInvoices,'pastInvoices'=>$pastInvoices]);
    }

    /** Abre a fatura na Vindi: só do próprio cliente e só para um endereço https dos domínios da Vindi. */
    public function openInvoice(Request $request,Invoice $invoice,VindiCheckoutService $vindi)
    {
        abort_unless($invoice->customer_id===$request->user()->customer_id,404);
        try {
            return redirect()->away($vindi->paymentUrl((string)$invoice->payment_url));
        } catch(\Throwable) {
            return redirect()->route('portal.invoices')->with('error','Esta fatura ainda não está disponível na Vindi. Tente de novo em instantes ou fale com a unidade.');
        }
    }

    /** Faturas em aberto (mais antigas primeiro) e já resolvidas (mais recentes primeiro). */
    private function splitInvoices(Customer $customer): array
    {
        $isOpen=fn($invoice)=>!in_array($invoice->status,['paid','cancelled','refunded'],true) && $invoice->outstanding_cents>0;
        [$open,$past]=$customer->invoices->partition($isOpen);
        return [$open->sortBy('due_at')->values(),$past->sortByDesc('due_at')->values()];
    }

    /** Situação de um benefício; o motivo comum a toda a contratação já aparece no aviso dela. */
    private function benefitState($rule,array $benefit,array $subscriptionCodes): array
    {
        $date=fn($value)=>$value?\Illuminate\Support\Carbon::parse($value)->timezone('America/Sao_Paulo')->format('d/m/Y'):null;
        $renewal=$rule->period_type!=='lifetime' && data_get($benefit,'period_end')?'Renova em '.$date($benefit['period_end']):null;
        if ($benefit['eligible']) {
            $remaining=$benefit['remaining'];
            return ['tone'=>'ok','label'=>'Disponível','detail'=>$remaining===null?'Sem limite de quantidade':'Saldo: '.$remaining.' de '.$rule->quantity_limit];
        }
        $issue=collect($benefit['impediments'])->first(fn($issue)=>!in_array($issue['code'],$subscriptionCodes,true));
        return match($issue['code'] ?? null) {
            'BENEFIT_WAITING_PERIOD'=>['tone'=>'wait','label'=>'Em carência','detail'=>'Liberado em '.$date($benefit['available_from'])],
            'BENEFIT_LIMIT_REACHED'=>['tone'=>'used','label'=>'Limite do período usado','detail'=>$renewal],
            'ADDITIONAL_APPROVAL_REQUIRED'=>['tone'=>'info','label'=>'Mediante autorização','detail'=>'Solicite na sua unidade'],
            'COVERAGE_FLOW_UNAVAILABLE'=>['tone'=>'info','label'=>'Aplicado no atendimento','detail'=>'Conforme o contrato'],
            null=>['tone'=>'wait','label'=>'Aguardando liberação','detail'=>$benefit['remaining']===null?null:'Saldo: '.$benefit['remaining'].' de '.$rule->quantity_limit],
            default=>['tone'=>'wait','label'=>$issue['message'],'detail'=>null],
        };
    }

    /** Pendências próprias da contratação, em linguagem do tutor. */
    private function notices($subscription,array $result): array
    {
        if ($result['eligible'] || $subscription->isTerminal()) return [];
        $codes=array_column($result['impediments'],'code');
        $messages=[];
        foreach($result['impediments'] as $issue) {
            if (in_array($issue['code'],self::CUSTOMER_WIDE,true)) continue;
            if ($issue['code']==='SUBSCRIPTION_NOT_ACTIVE' && count($codes)>1) continue;
            $messages[$issue['code']]=$issue['code']==='FUTURE_START'
                ? 'A vigência começa em '.$subscription->requested_start_at->timezone('America/Sao_Paulo')->format('d/m/Y').'.'
                : (self::TUTOR_MESSAGES[$issue['code']] ?? $issue['message']);
        }
        if (!$messages) {
            $messages[]=in_array('CUSTOMER_OVERDUE',$codes,true)
                ? 'Os benefícios voltam a ser liberados após a regularização dos pagamentos vencidos.'
                : 'Os benefícios são liberados assim que a verificação dos pagamentos for concluída.';
        }
        return array_values($messages);
    }
}

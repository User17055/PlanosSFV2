<?php

namespace App\Services\Reports;

use App\Models\{Customer, Franchise, Invoice, Payment, Product, Proposal, Subscription, User, VindiCancellation};
use App\Services\AccessService;
use App\Services\Domain\CommissionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardService
{
    public const STATES=['11'=>'RO','12'=>'AC','13'=>'AM','14'=>'RR','15'=>'PA','16'=>'AP','17'=>'TO','21'=>'MA','22'=>'PI','23'=>'CE','24'=>'RN','25'=>'PB','26'=>'PE','27'=>'AL','28'=>'SE','29'=>'BA','31'=>'MG','32'=>'ES','33'=>'RJ','35'=>'SP','41'=>'PR','42'=>'SC','43'=>'RS','50'=>'MS','51'=>'MT','52'=>'GO','53'=>'DF'];

    public function __construct(private AccessService $access) {}

    public static function normalize(?string $value): string
    {
        return Str::lower(Str::ascii(preg_replace('/\s+/u',' ',trim((string)$value))));
    }

    /** Nome do plano no painel: sem "(base antiga)" e sem variação ("+ 2 hemogramas", "+ taxa"), que conta no plano principal. */
    public static function planLabel(string $name): string
    {
        return trim(preg_replace('/\s*\+.*$|\s*\(base antiga\)$/u','',$name));
    }

    /** Mesmo plano da base antiga ("Plano Econômico"), da venda nova ("Econômico") e da Vindi ("PLANO ECONOMICO"). */
    private static function planKey(string $name): string
    {
        return preg_replace('/^plano /','',self::normalize(self::planLabel($name)));
    }

    /** Nome "Plano …" ou "Pacote …" quando algum dos nomes do grupo o tem. */
    private static function planDisplay($names): string
    {
        return collect($names)->map(fn($name)=>self::planLabel($name))->sortByDesc(fn($name)=>(int)preg_match('/^(plano|pacote)\b/iu',$name)*2+(int)($name!==mb_strtoupper($name)))->first();
    }

    /** Cancelamentos lidos da Vindi: só na carteira inteira e sem os filtros que eles não têm (vendedor, produto, região, origem). */
    private function vindiHistory(User $user,array $filters): ?Builder
    {
        if($filters['base']!=='gerais' || $filters['data_mode']!=='real' || $filters['employee_id'] || $filters['product_id'] || $filters['acquisition_source'] || $filters['state'] || $filters['city'] || $filters['neighborhood']) return null;
        $central=(int)Franchise::where('code','CENTRAL')->value('id');
        if(($filters['franchise_id'] && (int)$filters['franchise_id']!==$central) || ($user->role!=='central_admin' && !in_array($central,array_map('intval',$this->access->franchiseIds($user)),true))) return null;
        // Item que virou contrato aqui conta pelo contrato.
        return VindiCancellation::whereNotIn('vindi_product_item_id',Subscription::whereNotNull('vindi_product_item_id')->select('vindi_product_item_id'))
            ->when($filters['category']!=='all',fn($q)=>$q->where('category',$filters['category']));
    }

    public static function cityCatalog(): array
    {
        static $cities;
        return $cities??=json_decode(file_get_contents(public_path('assets/maps/municipios.json')),true,512,JSON_THROW_ON_ERROR);
    }

    public function filters(User $user,array $input): array
    {
        $today=CarbonImmutable::now('America/Sao_Paulo');
        $input=array_merge(['date_from'=>$today->startOfMonth()->toDateString(),'date_to'=>$today->toDateString(),'data_mode'=>'real','category'=>'health_plan','base'=>'gerais'],$input);
        $data=Validator::make($input,[
            'date_from'=>'required|date_format:Y-m-d|after_or_equal:2000-01-01|before_or_equal:'.$today->toDateString(),
            'date_to'=>'required|date_format:Y-m-d|after_or_equal:date_from|before_or_equal:'.$today->toDateString(),
            'franchise_id'=>'nullable|integer|exists:franchises,id','employee_id'=>'nullable|integer|exists:users,id',
            'product_id'=>'nullable|integer|exists:products,id','state'=>['nullable',Rule::in(array_values(self::STATES))],
            'city'=>'nullable|string|max:150','neighborhood'=>'nullable|string|max:150',
            'acquisition_source'=>['nullable',Rule::in(array_keys(\App\Services\Attribution\TrackingSanitizer::sourceLabels()))],
            'data_mode'=>['required',Rule::in(config('plans.demo')?['real','demo']:['real'])],
            'category'=>'required|in:health_plan,package,all',
            // Planos gerais: a carteira inteira, com a base antiga importada. Planos novos: só o que foi vendido pelo sistema.
            'base'=>'required|in:gerais,novos',
        ])->validate();
        if (CarbonImmutable::parse($data['date_from'])->diffInDays(CarbonImmutable::parse($data['date_to']))>365) throw ValidationException::withMessages(['date_to'=>'Selecione um período de até 366 dias.']);
        if (!empty($data['franchise_id'])) $this->access->franchise($user,(int)$data['franchise_id']);
        if ($user->role==='sales') {
            abort_if(!empty($data['employee_id']) && (int)$data['employee_id']!==$user->id,403);
            $data['employee_id']=$user->id;
        } elseif (!empty($data['employee_id'])) {
            abort_unless($this->employees($user)->whereKey($data['employee_id'])->exists(),403);
        }
        return array_merge(array_fill_keys(['franchise_id','employee_id','product_id','state','city','neighborhood','acquisition_source'],null),$data);
    }

    public function employees(User $user): Builder
    {
        return User::where('role','!=','customer')->when($user->role==='sales',fn($q)=>$q->whereKey($user->id))
            ->when(!in_array($user->role,['central_admin','sales']),fn($q)=>$q->whereHas('franchises',fn($f)=>$f->whereIn('franchises.id',$this->access->franchiseIds($user))->where('franchises.active',true)->where('user_franchises.active',true)));
    }

    public function attributed(Builder $query,int $id): Builder
    {
        return $query->where(fn($q)=>$q->where('created_by',$id)->orWhere(fn($q)=>$q->where('link_owner_id',$id)->whereHas('creator',fn($u)=>$u->where('role','customer'))));
    }

    public function proposals(User $user,array $filters): Builder
    {
        $query=$this->access->scope(Proposal::query(),$user);
        if($user->role!=='central_admin') $query->whereHas('originatingFranchise',fn($q)=>$q->where('active',true));
        if ($filters['franchise_id']) $query->where('originating_franchise_id',$filters['franchise_id']);
        if ($filters['employee_id']) $this->attributed($query,(int)$filters['employee_id']);
        if(!empty($filters['acquisition_source']))$query->where('acquisition_source',$filters['acquisition_source']);
        $query->whereHas('productVersion',function($q)use($filters){
            $this->versionMode($q,$filters['data_mode']);
            if($filters['product_id']) $q->where('product_id',$filters['product_id']);
        });
        if($filters['category']!=='all') $query->where('category',$filters['category']);
        return $query;
    }

    private function versionMode(Builder $query,string $mode): void
    {
        if($mode==='demo') $query->where(fn($q)=>$q->where('is_demo',true)->orWhere('status','local_preview'));
        else $query->where('is_demo',false)->where('status','!=','local_preview');
    }

    private function bounds(array $filters): array
    {
        return [CarbonImmutable::parse($filters['date_from'],'America/Sao_Paulo')->startOfDay()->utc(),CarbonImmutable::parse($filters['date_to'],'America/Sao_Paulo')->addDay()->startOfDay()->utc()];
    }

    private function during(Builder $query,string $column,array $bounds): Builder
    {
        return $query->where($column,'>=',$bounds[0])->where($column,'<',$bounds[1]);
    }

    private function geography(Builder $query,array $filters): Builder
    {
        if(!($filters['state']||$filters['city']||$filters['neighborhood'])) return $query;
        $ids=[];
        foreach(Customer::whereIn('id',(clone $query)->select('proposals.customer_id'))->get(['id','address']) as $customer) {
            if ($this->matches((array)$customer->address,$filters)) $ids[]=$customer->id;
        }
        return $query->whereIn('customer_id',$ids);
    }

    public function matches(array $address,array $filters): bool
    {
        foreach(['state','city'] as $key) if(!empty($filters[$key]) && self::normalize($address[$key]??'')!==self::normalize($filters[$key])) return false;
        return empty($filters['neighborhood']) || self::sameNeighborhood($address['neighborhood']??'',$filters['neighborhood']);
    }

    /** Bairro digitado sem o tipo ("Humberto Salvador") é o mesmo do mapa com o tipo ("Jardim Humberto Salvador"); tipos diferentes ("Vila" x "Parque Furquim") não. */
    public static function sameNeighborhood(?string $a,?string $b): bool
    {
        $strip=fn(string $v)=>preg_replace('/^(jardim|vila|parque|residencial|conjunto habitacional|conjunto|nucleo habitacional|nucleo|chacaras?|loteamento|condominio|bairro)\s+/','',$v);
        // "Jd." é "Jardim", "Vl." é "Vila" e assim por diante.
        $full=fn(?string $v)=>preg_replace(['/^jd\.?\s+/','/^vl\.?\s+/','/^pq\.?\s+/','/^res\.?\s+/','/^(cj|conj)\.?\s+/'],['jardim ','vila ','parque ','residencial ','conjunto '],self::normalize($v));
        [$a,$b]=[$full($a),$full($b)];
        return $a===$b || ($strip($a)===$strip($b) && ($strip($a)===$a || $strip($b)===$b));
    }

    public function report(User $user,array $filters): array
    {
        $bounds=$this->bounds($filters);
        $base=$this->proposals($user,$filters);
        // A base antiga nunca conta como venda; a carteira segue a escolha entre planos gerais e planos novos.
        $newOnly=fn(Builder $q)=>$q->whereDoesntHave('subscription',fn($s)=>$s->whereNotNull('imported_at'));
        $portfolio=fn(Builder $q)=>$filters['base']==='novos'?$q->whereNull('imported_at'):$q;
        $subscriptions=$portfolio(Subscription::whereIn('proposal_id',(clone $base)->select('proposals.id')));
        $allActive=(clone $subscriptions)->whereIn('status',['active','scheduled_cancellation'])
            ->with(['customer:id,address','pet:id,species','productVersion:id,product_id,name,billing_cycle','productVersion.product:id,name'])
            ->get(['id','customer_id','pet_id','category','product_version_id','price_cents','status']);
        $geo=$this->geoReport($allActive,$filters);
        $geoBase=$this->geography(clone $base,$filters);
        $scoped=$portfolio(Subscription::whereIn('proposal_id',(clone $geoBase)->select('proposals.id')));
        $invoices=Invoice::whereIn('subscription_id',(clone $scoped)->select('subscriptions.id'));
        $payments=Payment::whereHas('invoice',fn($q)=>$q->whereIn('invoices.id',(clone $invoices)->select('invoices.id'))->where('reconciliation_status','verified')->whereNotNull('confirmed_at')->whereIn('status',['paid','partial']));
        $payments->where('source',$filters['data_mode']==='demo'?'like':'not like','demo%');
        // Contract sales are accepted commitments; cash is independently dated by payment.
        $sold=$this->during($newOnly(clone $geoBase),'accepted_at',$bounds);
        $created=$this->during($newOnly(clone $geoBase),'created_at',$bounds);
        $periodInvoices=$this->during(clone $invoices,'due_at',$bounds);
        $open=(clone $periodInvoices)->whereNotIn('status',['cancelled','refunded','paid']);
        $commissionBase=app(CommissionService::class)->query($user)->whereIn('proposal_id',(clone $geoBase)->select('proposals.id'))->where('is_demo',$filters['data_mode']==='demo');
        if($filters['employee_id']) $commissionBase->where('beneficiary_user_id',$filters['employee_id']);
        $earned=$this->during(clone $commissionBase,'earned_at',$bounds);
        $paidCommissions=$this->during(clone $commissionBase,'paid_at',$bounds)->where(fn($q)=>$q->where('status','paid')->orWhere(fn($q)=>$q->where('status','reversed')->where('clawback',true)));
        $active=$allActive->filter(fn($s)=>$this->matches((array)$s->customer?->address,$filters));
        $summary=[
            'sold_count'=>(clone $sold)->count(),'sold_cents'=>(int)(clone $sold)->sum('price_cents'),
            'received_cents'=>(int)$this->during(clone $payments,'paid_at',$bounds)->sum('amount_cents'),
            'billed_cents'=>(int)(clone $periodInvoices)->whereNotIn('status',['cancelled','refunded'])->sum('amount_cents'),
            'open_cents'=>(int)(clone $open)->selectRaw('COALESCE(SUM(CASE WHEN amount_cents>paid_cents THEN amount_cents-paid_cents ELSE 0 END),0) as balance')->value('balance'),
            'overdue_cents'=>(int)(clone $open)->where('due_at','<',now())->selectRaw('COALESCE(SUM(CASE WHEN amount_cents>paid_cents THEN amount_cents-paid_cents ELSE 0 END),0) as balance')->value('balance'),
            'commission_payable_cents'=>(int)(clone $earned)->where('status','payable')->sum('amount_cents'),
            'commission_paid_cents'=>(int)(clone $paidCommissions)->sum('amount_cents'),
            'commission_held_count'=>(clone $earned)->whereIn('status',['held','awaiting_rate'])->count(),
            'clawback_cents'=>(int)(clone $commissionBase)->where('status','reversed')->where('clawback',true)->sum('amount_cents'),
            'active_count'=>$active->count(),'customers'=>$active->pluck('customer_id')->unique()->count(),
            'mrr_cents'=>(int)$active->where('productVersion.billing_cycle','monthly')->sum('price_cents'),
            'proposals_count'=>(clone $created)->count(),
            'pending_count'=>(clone $created)->whereNull('locked_at')->where('expires_at','>',now())->where('status','!=','cancelled')->count(),
            'cancelled_count'=>$this->during(clone $scoped,'cancelled_at',$bounds)->where('status','cancelled')->count(),
        ];
        $summary['ticket_cents']=$summary['sold_count']?(int)round($summary['sold_cents']/$summary['sold_count']):0;
        $summary['active_ticket_cents']=$summary['active_count']?(int)round($summary['mrr_cents']/$summary['active_count']):0;
        $summary['net_after_commissions_cents']=$summary['received_cents']-$summary['commission_paid_cents'];
        $cohortAccepted=(clone $created)->whereNotNull('accepted_at')->count();
        $summary['conversion']=$summary['proposals_count']?round($cohortAccepted*100/$summary['proposals_count'],1):0;
        $summary['cohort_accepted']=$cohortAccepted;
        $summary['cohort_documents']=(clone $created)->whereHas('subscription',fn($q)=>$q->where('document_status','valid'))->count();
        $summary['cohort_initial_paid']=(clone $created)->whereHas('subscription.invoices',fn($q)=>$q->where('is_initial',true)->where('status','paid')->where('reconciliation_status','verified')->whereNotNull('confirmed_at')->whereColumn('paid_cents','>=','amount_cents'))->count();
        $series=[];
        $cursor=CarbonImmutable::parse($filters['date_from'],'America/Sao_Paulo')->startOfMonth();
        $last=CarbonImmutable::parse($filters['date_to'],'America/Sao_Paulo');
        while($cursor<=$last) { $key=$cursor->format('Y-m'); $series[$key]=['month'=>$cursor->locale('pt_BR')->translatedFormat('M/y'),'sold_cents'=>0,'received_cents'=>0,'commission_cents'=>0]; $cursor=$cursor->addMonth(); }
        $sellerRows=[]; $planRows=[]; $channels=[];
        $sales=(clone $sold)->with(['creator:id,name,role','linkOwner:id,name','productVersion:id,product_id,name','productVersion.product:id,name'])->get(['id','customer_id','created_by','link_owner_id','product_version_id','channel','price_cents','accepted_at','acquisition_source','referrer_customer_id','referrer_name','campaign_name']);
        foreach($sales as $sale) {
            $key=$sale->accepted_at->timezone('America/Sao_Paulo')->format('Y-m'); if(isset($series[$key]))$series[$key]['sold_cents']+=$sale->price_cents;
            $employee=$sale->creator?->role!=='customer'?$sale->creator:$sale->linkOwner;
            $id=$employee?->id??0;
            $sellerRows[$id]??=['id'=>$id,'name'=>$employee?->name??'Contratação direta','sales'=>0,'sold_cents'=>0,'commission_cents'=>0];
            $sellerRows[$id]['sales']++; $sellerRows[$id]['sold_cents']+=$sale->price_cents;
            $pid=$sale->productVersion->product_id;
            $planRows[$pid]??=['name'=>$sale->productVersion->product->name,'sales'=>0,'sold_cents'=>0,'active'=>0];
            $planRows[$pid]['sales']++; $planRows[$pid]['sold_cents']+=$sale->price_cents;
            $channels[$sale->channel]=($channels[$sale->channel]??0)+1;
        }
        foreach($active as $s) { $pid=$s->productVersion->product_id; $planRows[$pid]??=['name'=>$s->productVersion->product->name,'sales'=>0,'sold_cents'=>0,'active'=>0]; $planRows[$pid]['active']++; $planRows[$pid]['mrr_cents']=($planRows[$pid]['mrr_cents']??0)+($s->productVersion->billing_cycle==='monthly'?$s->price_cents:0); }
        $insights=$this->portfolioInsights($filters,$active,$scoped,$invoices,$payments,$bounds,$sales);
        foreach($insights['plans'] as $pid=>$row) $planRows[$pid]=array_merge(['sales'=>0,'sold_cents'=>0,'active'=>0],$planRows[$pid]??[],$row);
        foreach($planRows as &$row)$row+=['received_cents'=>0,'cancelled'=>0,'month_expected_cents'=>0,'mrr_cents'=>0];unset($row);
        foreach($this->during(clone $payments,'paid_at',$bounds)->get(['paid_at','amount_cents']) as $p) { $key=$p->paid_at->timezone('America/Sao_Paulo')->format('Y-m'); if(isset($series[$key]))$series[$key]['received_cents']+=$p->amount_cents; }
        foreach((clone $paidCommissions)->get(['paid_at','amount_cents']) as $c) { $key=$c->paid_at->timezone('America/Sao_Paulo')->format('Y-m'); if(isset($series[$key]))$series[$key]['commission_cents']+=$c->amount_cents; }
        foreach((clone $earned)->whereIn('status',['payable','paid'])->get(['beneficiary_user_id','beneficiary_name','amount_cents']) as $c) {
            $id=$c->beneficiary_user_id??0; $sellerRows[$id]??=['id'=>$id,'name'=>$c->beneficiary_name,'sales'=>0,'sold_cents'=>0,'commission_cents'=>0]; $sellerRows[$id]['commission_cents']+=$c->amount_cents;
        }
        $growth=$this->growth($scoped,$filters,$this->vindiHistory($user,$filters),$payments,$bounds);
        $summary['cancelled_count']=$growth['cancellations']['period_count'];
        $summary['cancelled_lost_cents']=$growth['cancellations']['period_lost_cents'];
        $status=(clone $scoped)->whereIn('status',['suspended','awaiting_regularization'])->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total','status');
        $summary['suspended_count']=(int)($status['suspended']??0); $summary['awaiting_count']=(int)($status['awaiting_regularization']??0);
        // Cor fixa por plano: os 7 maiores da carteira inteira (sem filtro) ganham uma cor cada, na ordem da paleta; os demais ficam cinza.
        // Assim o filtro não repinta o mesmo plano com outra cor.
        $slots=Subscription::whereIn('subscriptions.status',['active','scheduled_cancellation'])->join('product_versions','product_versions.id','=','subscriptions.product_version_id')
            ->join('products','products.id','=','product_versions.product_id')->selectRaw('products.name as name, count(*) as total')->groupBy('products.name')->get()
            ->groupBy(fn($row)=>self::planKey($row->name))->map->sum('total')->sortDesc()->keys()->take(7)->flip();
        return compact('filters','summary','geo','series','channels','insights','growth')+[
            // Mesmo plano da base antiga ("Plano Econômico") e da venda nova ("Econômico"), e suas variações, numa linha só, com o nome "Plano …".
            'plans'=>collect($planRows)->groupBy(fn($row)=>self::planKey($row['name']))
                ->map(fn($rows,$key)=>collect(['sales','sold_cents','active','mrr_cents','received_cents','cancelled','month_expected_cents'])->mapWithKeys(fn($k)=>[$k=>$rows->sum($k)])
                    ->put('name',self::planDisplay($rows->pluck('name')))->put('slot',$slots[$key]??null)->all())
                ->sortByDesc($filters['base']==='gerais'?'active':'sales')->values(),
            'ranking'=>collect($sellerRows)->sortByDesc('sales')->values()->take(10),
            'recentProposals'=>$newOnly(clone $geoBase)->with(['customer:id,name','pet:id,name','productVersion:id,name','originatingFranchise:id,name'])->latest()->take(6)->get(),
            'options'=>['employees'=>$this->employees($user)->orderBy('name')->get(['id','name']),
                'franchises'=>Franchise::when($user->role!=='central_admin',fn($q)=>$q->whereIn('id',$this->access->franchiseIds($user)))->orderBy('name')->get(['id','name']),
                'products'=>Product::whereHas('versions',function($q)use($filters){
                    if($filters['data_mode']==='demo') $this->versionMode($q,'demo');
                    else $q->where('is_demo',false);
                })->orderBy('name')->get(['id','name'])],
            'isSales'=>$user->role==='sales','generatedAt'=>CarbonImmutable::now('America/Sao_Paulo'),
        ];
    }

    /** Carteira mês a mês no período do filtro: ativos e mensalidade no fim de cada mês, quem entrou e quem saiu. */
    private function growth(Builder $scoped,array $filters,?Builder $vindi,Builder $payments,array $bounds): array
    {
        $rows=(clone $scoped)->where(fn($q)=>$q->whereNotNull('activated_at')->orWhereNotNull('imported_at')->orWhereIn('status',['active','scheduled_cancellation']))
            ->with('productVersion:id,product_id,billing_cycle','productVersion.product:id,name')->get(['id','product_version_id','price_cents','status','locked_at','activated_at','effective_start_at','cancelled_at','ends_at','updated_at','imported_at'])
            ->map(fn($s)=>['start'=>$s->effective_start_at??$s->activated_at??$s->locked_at,'stop'=>$s->cancelled_at??($s->isTerminal()?($s->ends_at??$s->updated_at):null),
                'cents'=>$s->productVersion?->billing_cycle==='monthly'?$s->price_cents:0,'plan'=>$s->productVersion?->product?->name??'','replaced'=>false])->filter(fn($r)=>$r['start']);
        // Base antiga: quem cancelou na Vindi, antes ou depois da importação, também conta. Cancelada e refeita na hora não é saída nem entrada.
        if($vindi) $rows=$rows->concat((clone $vindi)->whereNotNull('started_at')->get(['product_name','price_cents','started_at','cancelled_at','replaced'])
            ->map(fn($c)=>['start'=>$c->started_at,'stop'=>$c->cancelled_at,'cents'=>$c->price_cents,'plan'=>$c->product_name,'replaced'=>$c->replaced]))->values();
        $alive=fn($at)=>$rows->filter(fn($r)=>$r['start']->lt($at) && (!$r['stop'] || $r['stop']->gte($at)));
        $within=fn(string $key,$from,$to)=>$rows->filter(fn($r)=>$r[$key] && $r[$key]->gte($from) && $r[$key]->lt($to));
        $last=CarbonImmutable::parse($filters['date_to'],'America/Sao_Paulo')->startOfMonth();
        $begin=CarbonImmutable::parse($filters['date_from'],'America/Sao_Paulo')->startOfMonth();
        // Período de um mês só, como o padrão: mostra os 12 meses até ele, para o crescimento aparecer.
        if($begin->equalTo($last)) $begin=$last->subMonths(11);
        $received=[];
        foreach($this->during(clone $payments,'paid_at',[$begin->utc(),$last->addMonth()->utc()])->get(['paid_at','amount_cents']) as $p) { $key=$p->paid_at->timezone('America/Sao_Paulo')->format('Y-m'); $received[$key]=($received[$key]??0)+$p->amount_cents; }
        $months=[];
        for($month=$begin;$month<=$last;$month=$month->addMonth()) {
            $from=$month->utc(); $to=$month->addMonth()->utc(); $at=$to->lt(CarbonImmutable::now())?$to:CarbonImmutable::now()->addSecond()->utc(); // mês atual: conta até agora, inclusive
            $now=$alive($at); $before=$alive($from)->count();
            $stops=$within('stop',$from,$to); $replaced=$stops->where('replaced',true)->count(); $left=$stops->where('replaced',false);
            $months[]=['month'=>$month->locale('pt_BR')->translatedFormat('M/y'),'active'=>$now->count(),'mrr_cents'=>(int)$now->sum('cents'),
                'entered'=>max(0,$within('start',$from,$to)->count()-$replaced),'left'=>$left->count(),'replaced'=>$replaced,'lost_cents'=>(int)$left->sum('cents'),
                'churn'=>$before?round($left->count()*100/$before,1):null,'received_cents'=>$received[$month->format('Y-m')]??0];
        }
        $start=$alive($begin->utc());
        $first=['active'=>$start->count(),'mrr_cents'=>(int)$start->sum('cents')];
        $end=end($months);
        // Quem saiu na janela do gráfico: por plano e por quanto tempo ficou.
        $gone=$within('stop',$begin->utc(),$last->addMonth()->utc())->where('replaced',false);
        $tenure=array_fill_keys(['Até 3 meses','3 a 6 meses','6 a 12 meses','Mais de 1 ano'],0);
        foreach($gone as $r){ $m=(int)floor(abs($r['start']->diffInMonths($r['stop']))); $tenure[$m<3?'Até 3 meses':($m<6?'3 a 6 meses':($m<12?'6 a 12 meses':'Mais de 1 ano'))]++; }
        $period=$within('stop',$bounds[0],$bounds[1])->where('replaced',false);
        $churns=array_filter(array_column($months,'churn'),fn($v)=>$v!==null);
        return ['months'=>$months,'start'=>$first,'range_label'=>count($months)===1?'no mês':'em '.count($months).' meses','active_delta'=>$end['active']-$first['active'],'mrr_delta_cents'=>$end['mrr_cents']-$first['mrr_cents'],
            'active_growth'=>$first['active']?round(($end['active']-$first['active'])*100/$first['active'],1):null,
            'entered'=>array_sum(array_column($months,'entered')),'left'=>array_sum(array_column($months,'left')),'max'=>max(1,...array_column($months,'active')),
            'received_cents'=>array_sum(array_column($months,'received_cents')),'with_vindi'=>(bool)$vindi,
            'cancellations'=>['period_count'=>$period->count(),'period_lost_cents'=>(int)$period->sum('cents'),'count'=>$gone->count(),'lost_cents'=>(int)$gone->sum('cents'),
                'replaced'=>array_sum(array_column($months,'replaced')),'churn'=>$churns?round(array_sum($churns)/count($churns),1):null,'tenure'=>$tenure,
                'plans'=>$gone->groupBy(fn($r)=>self::planKey($r['plan']))->map(fn($g)=>['name'=>self::planDisplay($g->pluck('plan')),'count'=>$g->count(),'lost_cents'=>(int)$g->sum('cents')])->sortByDesc('count')->values()->take(6)->all()]];
    }

    private function portfolioInsights(array $filters,$active,Builder $subscriptions,Builder $invoices,Builder $payments,array $bounds,$sales): array
    {
        $pets=$active->where('category','health_plan')->unique('pet_id');
        $species=['dog'=>$pets->filter(fn($s)=>$s->pet?->species==='dog')->count(),'cat'=>$pets->filter(fn($s)=>$s->pet?->species==='cat')->count()];
        $month=CarbonImmutable::parse($filters['date_to'],'America/Sao_Paulo')->startOfMonth();
        $monthBounds=[$month->utc(),$month->addMonth()->utc()];
        $monthlyInvoices=$this->during(clone $invoices,'due_at',$monthBounds)->whereNotIn('status',['cancelled','refunded']);
        $monthExpected=(int)(clone $monthlyInvoices)->sum('amount_cents');
        $monthOpen=(int)(clone $monthlyInvoices)->selectRaw('COALESCE(SUM(CASE WHEN amount_cents>paid_cents THEN amount_cents-paid_cents ELSE 0 END),0) as balance')->value('balance');
        $monthReceived=(int)$this->during(clone $payments,'paid_at',[$month->utc(),min($month->addMonth()->utc(),CarbonImmutable::now()->addSecond())])->sum('amount_cents');
        $plans=[]; $origins=[]; $referrals=[];
        $plan=function($version)use(&$plans){
            $pid=$version->product_id;
            $plans[$pid]??=['name'=>$version->product->name,'received_cents'=>0,'cancelled'=>0,'month_expected_cents'=>0];
            return $pid;
        };
        $origin=function($source)use(&$origins){
            $source=$source?:'unknown';
            $origins[$source]??=['source'=>$source,'label'=>\App\Services\Attribution\TrackingSanitizer::sourceLabels()[$source]??'Não informado','sales'=>0,'customers'=>[],'received_cents'=>0];
            return $source;
        };
        $referral=function($proposal)use(&$referrals){
            if(!filled($proposal->referrer_name))return null;
            $key=$proposal->referrer_customer_id?'id:'.$proposal->referrer_customer_id:'name:'.self::normalize($proposal->referrer_name);
            $referrals[$key]??=['name'=>$proposal->referrer_name,'registered'=>(bool)$proposal->referrer_customer_id,'sales'=>0,'customers'=>[],'received_cents'=>0];
            return $key;
        };
        foreach($sales as $sale){
            $key=$origin($sale->acquisition_source);$origins[$key]['sales']++;$origins[$key]['customers'][$sale->customer_id]=true;
            if(($key=$referral($sale))!==null){$referrals[$key]['sales']++;$referrals[$key]['customers'][$sale->customer_id]=true;}
        }
        foreach($this->during(clone $payments,'paid_at',$bounds)->with('invoice.subscription.productVersion.product','invoice.subscription.proposal')->get() as $payment){
            $subscription=$payment->invoice->subscription;$pid=$plan($subscription->productVersion);
            $plans[$pid]['received_cents']+=$payment->amount_cents;
            $key=$origin($subscription->proposal->acquisition_source);$origins[$key]['received_cents']+=$payment->amount_cents;
            if(($key=$referral($subscription->proposal))!==null)$referrals[$key]['received_cents']+=$payment->amount_cents;
        }
        foreach((clone $monthlyInvoices)->with('subscription.productVersion.product')->get() as $invoice){$pid=$plan($invoice->subscription->productVersion);$plans[$pid]['month_expected_cents']+=$invoice->amount_cents;}
        $cancelled=$this->during(clone $subscriptions,'cancelled_at',$bounds)->where('status','cancelled');
        foreach((clone $cancelled)->with('productVersion.product')->get() as $subscription){$pid=$plan($subscription->productVersion);$plans[$pid]['cancelled']++;}
        $bestCount=(int)$sales->groupBy(fn($sale)=>$sale->productVersion->product_id)->map->count()->max();
        $best=$bestCount?$sales->groupBy(fn($sale)=>$sale->productVersion->product_id)->filter(fn($group)=>$group->count()===$bestCount)->map(fn($group)=>$group->first()->productVersion->product->name)->values()->all():[];
        $finish=fn($rows)=>collect($rows)->map(fn($row)=>array_merge($row,['customers'=>count($row['customers'])]))->sortByDesc('sales')->values();
        return ['species'=>$species,'month_label'=>$month->locale('pt_BR')->translatedFormat('F/Y'),'month_expected_cents'=>$monthExpected,'month_open_cents'=>$monthOpen,'month_received_cents'=>$monthReceived,
            'plans'=>$plans,'best_sellers'=>$best,'best_sales'=>$bestCount,'origins'=>$finish($origins),'referrals'=>$finish($referrals)->take(10),
            'recent_cancelled'=>(clone $cancelled)->with(['customer:id,name','pet:id,name','productVersion:id,name'])->orderByDesc('cancelled_at')->limit(8)->get()];
    }

    private function geoReport($active,array $filters): array
    {
        $states=[]; $cities=[]; $neighborhoods=[]; $missing=0; $mapped=0; $activeCount=0; $pendingCount=0;
        $catalog=[];
        foreach(self::cityCatalog() as $code=>$entry) $catalog[self::STATES[$entry['state']].'|'.self::normalize($entry['name'])]=['code'=>(string)$code,'name'=>$entry['name'],'state'=>self::STATES[$entry['state']]];
        $mapCities=['3541406'=>['code'=>'3541406','name'=>'Presidente Prudente','state'=>'SP']];
        foreach($active as $s) {
            $isActive=in_array($s->status,['active','scheduled_cancellation'],true);
            $a=(array)$s->customer?->address;
            $state=strtoupper(trim($a['state']??'')); $city=trim($a['city']??''); $neighborhood=trim($a['neighborhood']??'');
            if (!in_array($state,self::STATES,true)||!$city) { $missing++; continue; }
            $key=$state.'|'.self::normalize($city);
            if(!isset($catalog[$key])) { $missing++; continue; }
            $entry=$catalog[$key]; $city=$entry['name']; $mapped++;
            $isActive?$activeCount++:$pendingCount++;
            $mapCities[$entry['code']]=$entry;
            $states[$state]??=['state'=>$state,'plans'=>0,'active_plans'=>0,'customers'=>[]]; $states[$state]['plans']++; $states[$state]['active_plans']+=(int)$isActive; $states[$state]['customers'][$s->customer_id]=true;
            $cities[$key]??=['state'=>$state,'city'=>$city,'plans'=>0,'active_plans'=>0,'customers'=>[]]; $cities[$key]['plans']++; $cities[$key]['active_plans']+=(int)$isActive; $cities[$key]['customers'][$s->customer_id]=true;
            if(!$this->matches($a,array_merge($filters,['neighborhood'=>null]))) continue;
            $nkey=$key.'|'.self::normalize($neighborhood);
            $neighborhoods[$nkey]??=['state'=>$state,'city'=>$city,'name'=>$neighborhood?:'Bairro não informado','plans'=>0,'active_plans'=>0,'customers'=>[],'missing'=>!$neighborhood];
            $neighborhoods[$nkey]['plans']++; $neighborhoods[$nkey]['active_plans']+=(int)$isActive; $neighborhoods[$nkey]['customers'][$s->customer_id]=true;
        }
        $finish=fn($rows)=>collect($rows)->map(fn($r)=>array_merge($r,['customers'=>count($r['customers'])]))->sortByDesc('customers')->values()->all();
        return ['states'=>$finish($states),'cities'=>$finish($cities),'neighborhoods'=>$finish($neighborhoods),'missing_address'=>$missing,'map_cities'=>array_values($mapCities),'total'=>$mapped,'active_total'=>$activeCount,'pending_total'=>$pendingCount];
    }
}

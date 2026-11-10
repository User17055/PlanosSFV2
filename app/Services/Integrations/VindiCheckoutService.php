<?php

namespace App\Services\Integrations;

use App\Models\{Customer,Invoice,Subscription};
use App\Services\Domain\{Audit,BillingSchedule};
use Illuminate\Support\Facades\{Cache,DB};

class VindiCheckoutService
{
    public function __construct(private VindiClient $client, private VindiReconciler $reconciler) {}

    public function available(Subscription $subscription): bool
    {
        $version=$subscription->productVersion;
        return $this->client->configured() && config('integrations.vindi.allow_creation')
            && config('integrations.vindi.checkout_enabled') && config('plans.commercial_approved')
            && !$version->is_demo && $version->status==='published'
            && !$subscription->isTerminal() && $subscription->document_status==='valid'
            && $subscription->proposal->accepted_at
            && $this->mapping($subscription)!==null;
    }

    private function mapping(Subscription $subscription): ?array
    {
        $map=config('integrations.vindi.checkout_plans',[])[$subscription->product_version_id] ?? null;
        $version=data_get($subscription->contract_snapshot,'version',[]);
        if (!is_array($map) || !($map['approved']??false) || !preg_match('/^[1-9][0-9]*$/D',(string)($map['plan_id']??''))) return null;
        // This explicitly homologated plan includes its billing period, renewal and all fees.
        foreach (['price_cents','enrollment_fee_cents','billing_cycle','duration_months','renewal_policy'] as $field) {
            if (!array_key_exists($field,$map) || !array_key_exists($field,$version) || (string)$map[$field] !== (string)$version[$field]) return null;
        }
        return $map;
    }

    /** Payment methods offered to the tutor, code => label. Falls back to the single configured method. */
    public function paymentMethods(): array
    {
        $methods=config('integrations.vindi.payment_methods',[]);
        return $methods ?: [(string)config('integrations.vindi.payment_method_code')=>'Pagamento online'];
    }

    public function start(Subscription $subscription,?string $method=null): string
    {
        $subscription=$subscription->fresh(['productVersion','proposal','customer']);
        if (!$this->available($subscription)) throw new IntegrationPendingException('O pagamento online deste contrato ainda não está disponível. Fale com a unidade responsável.');
        if ($method!==null && !array_key_exists($method,$this->paymentMethods())) throw new IntegrationPendingException('Esta forma de pagamento não está disponível. Escolha outra opção.');
        // The tutor may change the method until the entry bill exists; after that the original choice is kept.
        if (!Invoice::where('subscription_id',$subscription->id)->where('is_initial',true)->value('external_id')) {
            $subscription->update(['payment_method_code'=>$method ?? $subscription->payment_method_code ?? array_key_first($this->paymentMethods())]);
        }
        $method=$subscription->payment_method_code ?? array_key_first($this->paymentMethods());
        $account=(string)config('integrations.vindi.provider_account');
        $prefix=(string)config('integrations.vindi.code_prefix');
        if (!preg_match('/^[a-zA-Z0-9_-]{1,50}$/D',$prefix)) throw new IntegrationPendingException('Configuração do pagamento pendente. Fale com a unidade responsável.');
        $code=$prefix.'-'.$subscription->number;
        $customerCode=$prefix.'-tutor-'.$subscription->customer_id;
        $map=$this->mapping($subscription);
        // Só na primeira tentativa: uma tentativa já registrada passou por esta conferência e segue a recuperação por GET.
        if (!DB::table('vindi_checkouts')->where('subscription_id',$subscription->id)->exists() && $this->paysOutsideSubscription($subscription,$prefix)) {
            Audit::record('subscription.checkout_blocked_existing_vindi',$subscription);
            throw new IntegrationPendingException('Você já tem uma assinatura ativa com a São Francisco. Para não haver cobrança em dobro, a unidade vai ligar este contrato a ela. Fale com a unidade.');
        }
        $claim=DB::transaction(function() use($subscription,$account,$code) {
            Customer::lockForUpdate()->findOrFail($subscription->customer_id);
            $current=Subscription::lockForUpdate()->findOrFail($subscription->id);
            if ($current->isTerminal() || $current->document_status!=='valid') throw new IntegrationPendingException('Este contrato não está disponível para pagamento.');
            $initial=Invoice::where('subscription_id',$current->id)->where('is_initial',true)->sole();
            if (in_array($initial->status,['paid','cancelled','refunded','disputed'],true)) throw new IntegrationPendingException('Confira a situação atual deste pagamento no portal.');
            $attempt=DB::table('vindi_checkouts')->where('subscription_id',$current->id)->first();
            if ($attempt) {
                if ($attempt->provider_account!==$account || $attempt->reference!==$code) throw new IntegrationPendingException('Este pagamento exige conferência da unidade responsável.');
                if ($attempt->status==='processing' && now()->diffInSeconds($attempt->updated_at,true)<120) throw new IntegrationPendingException('Seu pagamento está sendo preparado. Aguarde um momento e consulte novamente.');
                return false; // Only GET recovery is allowed after any uncertain result.
            }
            if (DB::table('vindi_checkouts')->where('customer_id',$current->customer_id)->whereIn('status',['processing','uncertain'])->exists()) throw new IntegrationPendingException('Há um pagamento em conferência. Fale com a unidade antes de iniciar outro.');
            if ($current->vindi_subscription_id) throw new IntegrationPendingException('Este contrato já possui cobrança vinculada. Consulte suas faturas no portal.');
            DB::table('vindi_checkouts')->insert(['subscription_id'=>$current->id,'customer_id'=>$current->customer_id,'provider_account'=>$account,'reference'=>$code,'status'=>'processing','created_at'=>now(),'updated_at'=>now()]);
            return true;
        },3);

        try {
            $invoice=Invoice::where('subscription_id',$subscription->id)->where('is_initial',true)->sole();
            if ($subscription->productVersion->billing_cycle==='monthly') {
                // Only the entry (first installment) is charged now, as a standalone bill that validates the plan.
                // The open-ended subscription for the following months is created once this bill is confirmed as paid.
                $customer=$this->resolveCustomer($subscription,$customerCode);
                $this->validateCustomer($customer,$subscription);
                $this->linkCustomer($subscription,(string)$customer['id']);
                $bill=$this->entryBill($subscription,$invoice,$map,$code.'-entrada',$method);
                if (data_get($bill,'subscription.id') || (string)data_get($bill,'customer.id')!==(string)$subscription->customer->fresh()->vindi_customer_id
                    || VindiReconciler::decimalToCents($bill['amount']??null)!==$invoice->amount_cents) throw new \RuntimeException('Entry bill requires reconciliation.');
            } else {
                $matches=collect($this->client->subscriptionsByCode($code))->where('code',$code)->values();
                if ($matches->count()>1) throw new \RuntimeException('Ambiguous provider reference.');
                $remote=$matches->first();
                if (!$remote) {
                    if (!$claim) throw new \RuntimeException('Uncertain operation requires manual reconciliation.');
                    $customer=$this->resolveCustomer($subscription,$customerCode);
                    $this->validateCustomer($customer,$subscription);
                    $this->linkCustomer($subscription,(string)$customer['id']);
                    $remote=$this->client->createSubscription(['code'=>$code,'plan_id'=>(int)$map['plan_id'],'customer_id'=>(int)$customer['id'],
                        'payment_method_code'=>$method]);
                }
                $externalId=$this->persistRemoteSubscription($subscription,$remote,$code,$map,$account);
                $bills=$this->client->billsForSubscription($externalId);
                $bill=$invoice->external_id ? collect($bills)->firstWhere('id',$invoice->external_id) : (count($bills)===1?$bills[0]:null);
                if (!$bill || (string)data_get($bill,'subscription.id')!==$externalId || VindiReconciler::decimalToCents($bill['amount']??null)!==$invoice->amount_cents) throw new \RuntimeException('Initial bill requires reconciliation.');
            }
            $url=$this->paymentUrl($bill['url']??'');
            $billId=(string)($bill['id']??'');
            if (!preg_match('/^[1-9][0-9]*$/D',$billId)) throw new \RuntimeException('Invalid bill identifier.');
            DB::transaction(function() use($subscription,$invoice,$billId,$account) {
                Customer::lockForUpdate()->findOrFail($subscription->customer_id);
                Subscription::lockForUpdate()->findOrFail($subscription->id);
                $current=Invoice::lockForUpdate()->findOrFail($invoice->id);
                if ($current->external_id && ($current->external_id!==$billId || $current->provider_account!==$account)) throw new \RuntimeException('Bill already linked elsewhere.');
                $current->update(['external_id'=>$billId,'provider_account'=>$account]);
            },3);
            // Only the authenticated GET bill response can confirm payment.
            $verified=$this->reconciler->reconcileBill($billId,$account);
            DB::transaction(function() use($subscription,$verified,$url) {
                Customer::lockForUpdate()->findOrFail($subscription->customer_id);
                $current=Subscription::lockForUpdate()->findOrFail($subscription->id);
                if ($current->isTerminal()) throw new \RuntimeException('Contract ended during checkout.');
                $verified->update(['payment_url'=>$verified->status==='pending'?$url:null]);
                DB::table('vindi_checkouts')->where('subscription_id',$subscription->id)->update(['status'=>'ready','updated_at'=>now()]);
            },3);
            return $url;
        } catch (\Throwable $exception) {
            // Our own RuntimeException messages carry no personal data; provider bodies are never logged.
            \Illuminate\Support\Facades\Log::warning('Vindi: pagamento não iniciado.',['assinatura'=>$subscription->id]+VindiClient::failureReason($exception)
                +['motivo'=>get_class($exception)===\RuntimeException::class?$exception->getMessage():null]);
            DB::table('vindi_checkouts')->where('subscription_id',$subscription->id)->update(['status'=>'uncertain','updated_at'=>now()]);
            Invoice::where('subscription_id',$subscription->id)->where('is_initial',true)->update(['payment_url'=>null]);
            // Never expose provider response bodies, contact data or authorization headers.
            throw new IntegrationPendingException('Estamos conferindo a emissão do seu pagamento. Consulte o portal ou fale com a unidade. Uma nova cobrança não será criada automaticamente.');
        }
    }

    /**
     * After the entry is confirmed as paid, opens the Vindi subscription for the following monthly installments.
     * It has no end date and starts on the generation day chosen by the cutoff rule. Created after the payment,
     * it is charged on the card the tutor has just used. Safe to call repeatedly: an existing one is only linked.
     */
    public function startRecurring(Subscription $subscription): ?string
    {
        $subscription=$subscription->fresh(['productVersion','proposal','customer']);
        if ($subscription->vindi_subscription_id) return $subscription->vindi_subscription_id;
        $entry=Invoice::where('subscription_id',$subscription->id)->where('is_initial',true)->first();
        if ($subscription->productVersion->billing_cycle!=='monthly' || $subscription->isTerminal() || $entry?->status!=='paid' || !$entry->external_id) return null;
        $map=$this->mapping($subscription);
        if (!$map || !$subscription->customer->vindi_customer_id) throw new IntegrationPendingException('Plano ou cliente Vindi não vinculado para as próximas parcelas; conferência manual necessária.');
        $account=(string)config('integrations.vindi.provider_account');
        $code=(string)config('integrations.vindi.code_prefix').'-'.$subscription->number;
        // Por cliente: dois pets pagos ao mesmo tempo não abrem duas assinaturas; quem não pega a vez volta na conferência periódica.
        $lock=Cache::lock('vindi-recurring-customer-'.$subscription->customer_id,120);
        if (!$lock->get()) return null;
        try {
            $matches=collect($this->client->subscriptionsByCode($code))->where('code',$code)->values();
            if ($matches->count()>1) throw new IntegrationPendingException('Mais de uma assinatura Vindi com a mesma referência; conferência manual necessária.');
            $first=app(BillingSchedule::class)->firstRecurringBilling($subscription->proposal->accepted_at ?? $entry->paid_at ?? now());
            $shared=$matches->isEmpty() ? $this->customerSubscription($subscription,$map,$account) : null;
            if ($shared) return $this->addPet($subscription,$shared,$map,$first,$account);
            $remote=$matches->first() ?? $this->client->createSubscription(['code'=>$code,'plan_id'=>(int)$map['plan_id'],
                'customer_id'=>(int)$subscription->customer->vindi_customer_id,'payment_method_code'=>$subscription->payment_method_code ?? array_key_first($this->paymentMethods()),
                'start_at'=>$first->toIso8601String(),'billing_trigger_type'=>'beginning_of_period','metadata'=>['contrato'=>$subscription->number]]);
            return $this->persistRemoteSubscription($subscription,$remote,$code,$map,$account);
        } finally {
            $lock->release();
        }
    }

    private function persistRemoteSubscription(Subscription $subscription,array $remote,string $code,array $map,string $account): string
    {
        if (($remote['code']??null)!==$code || (string)data_get($remote,'plan.id')!==(string)$map['plan_id'] || !preg_match('/^[1-9][0-9]*$/D',(string)($remote['id']??''))) throw new \RuntimeException('Provider subscription mismatch.');
        $this->validateCustomer($this->client->customer((string)data_get($remote,'customer.id')),$subscription);
        $this->linkCustomer($subscription,(string)data_get($remote,'customer.id'));
        $externalId=(string)$remote['id'];
        // O único item de uma assinatura recém-aberta é o deste pet; os próximos pets entram como outros itens.
        $items=collect($remote['product_items']??[]);
        $this->link($subscription,$externalId,$items->count()===1?(string)$items->first()['id']:null,$account);
        return $externalId;
    }

    // Persist the subscription identity before fetching bills; a webhook can be retried.
    private function link(Subscription $subscription,string $externalId,?string $itemId,string $account): void
    {
        DB::transaction(function() use($subscription,$externalId,$itemId,$account) {
            Customer::lockForUpdate()->findOrFail($subscription->customer_id);
            $current=Subscription::lockForUpdate()->findOrFail($subscription->id);
            if ($current->isTerminal() || ($current->vindi_subscription_id && $current->vindi_subscription_id!==$externalId)) throw new \RuntimeException('Contract changed during checkout.');
            $current->update(['provider_account'=>$account,'vindi_subscription_id'=>$externalId]+($itemId?['vindi_product_item_id'=>$itemId]:[]));
        },3);
    }

    /** A assinatura mensal que outro pet do mesmo cliente e do mesmo plano já tem na Vindi; este pet entra nela, como nas feitas pelo painel. */
    private function customerSubscription(Subscription $subscription,array $map,string $account): ?array
    {
        $other=Subscription::where('customer_id',$subscription->customer_id)->where('product_version_id',$subscription->product_version_id)
            ->where('provider_account',$account)->whereKeyNot($subscription->id)->whereNotNull('vindi_subscription_id')
            ->whereNotIn('status',Subscription::TERMINAL)->orderBy('id')->first();
        if (!$other) return null;
        $remote=$this->client->subscription($other->vindi_subscription_id);
        // Cancelada na Vindi, ou de outro plano ou cliente: este pet abre a própria.
        return in_array($remote['status']??null,['active','future'],true) && (string)data_get($remote,'plan.id')===(string)$map['plan_id']
            && (string)data_get($remote,'customer.id')===(string)$subscription->customer->vindi_customer_id ? $remote : null;
    }

    /** Um item por pet na mesma assinatura: a fatura mensal sai uma só, com um item de cada contrato. */
    private function addPet(Subscription $subscription,array $remote,array $map,\Carbon\CarbonInterface $first,string $account): ?string
    {
        $externalId=(string)$remote['id'];
        // O item novo entra na próxima fatura da assinatura, que tem de ser a do mês da primeira mensalidade deste pet.
        $next=\Carbon\CarbonImmutable::parse((string)($remote['next_billing_at']??''))->setTimezone('America/Sao_Paulo')->format('Y-m');
        if ($next<$first->format('Y-m')) return null; // ainda falta a fatura deste mês sem ele: a conferência periódica tenta de novo depois
        if ($next>$first->format('Y-m')) throw new IntegrationPendingException('A próxima fatura da assinatura Vindi deste cliente é depois da primeira mensalidade deste pet; conferência manual necessária.');
        $linked=Subscription::where('provider_account',$account)->where('vindi_subscription_id',$externalId)->get();
        $items=collect($remote['product_items']??[])->where('status','active');
        // Contrato de antes do item por pet: o único item da assinatura é dele.
        if ($linked->count()===1 && !$linked->first()->vindi_product_item_id && $items->count()===1) {
            $linked->first()->update(['vindi_product_item_id'=>(string)$items->first()['id']]);
        }
        $claimed=$linked->map->fresh()->pluck('vindi_product_item_id');
        if ($claimed->contains(null)) throw new IntegrationPendingException('Assinatura Vindi com pet sem item identificado; conferência manual necessária.');
        $product=$this->planProduct($map);
        // Item criado numa tentativa que caiu antes de gravar aqui: é reaproveitado, não duplicado.
        $item=$items->first(fn($i)=>!$claimed->contains((string)$i['id']) && (string)data_get($i,'product.id')===(string)$product
            && VindiReconciler::decimalToCents(data_get($i,'pricing_schema.price'))===$subscription->price_cents)
            ?? $this->client->createProductItem(['subscription_id'=>(int)$externalId,'product_id'=>$product,'quantity'=>1,
                'pricing_schema'=>['price'=>number_format($subscription->price_cents/100,2,'.',''),'schema_type'=>'flat']]);
        if (!preg_match('/^[1-9][0-9]*$/D',(string)($item['id']??''))) throw new \RuntimeException('Invalid product item.');
        $this->link($subscription,$externalId,(string)$item['id'],$account);
        Audit::record('subscription.vindi_item_added',$subscription,null,['vindi_subscription_id'=>$externalId,'vindi_product_item_id'=>(string)$item['id']]);
        return $externalId;
    }

    private function planProduct(array $map): int
    {
        $items=collect(data_get($this->client->plan((string)$map['plan_id']),'plan_items',[]));
        if ($items->count()!==1 || !preg_match('/^[1-9][0-9]*$/D',(string)data_get($items->first(),'product.id'))) throw new \RuntimeException('Plan product is not unambiguous.');
        return (int)data_get($items->first(),'product.id');
    }

    private function entryBill(Subscription $subscription,Invoice $invoice,array $map,string $code,string $method): array
    {
        if ($invoice->external_id) return $this->client->bill($invoice->external_id);
        $matches=collect($this->client->billsByCode($code))->where('code',$code)->values();
        if ($matches->count()>1) throw new \RuntimeException('Ambiguous entry bill.');
        if ($matches->isNotEmpty()) return $matches->first();
        return $this->client->createBill(['customer_id'=>(int)$subscription->customer->fresh()->vindi_customer_id,'code'=>$code,
            'payment_method_code'=>$method,'due_at'=>$invoice->due_at->timezone('America/Sao_Paulo')->toDateString(),
            'bill_items'=>[['product_id'=>$this->planProduct($map),'amount'=>number_format($invoice->amount_cents/100,2,'.','')]],
            'metadata'=>['contrato'=>$subscription->number,'parcela'=>'entrada']]);
    }

    /**
     * One Vindi customer per CPF. Reuses the linked record, then one found by our code, then one already
     * registered with the same CPF; creates a new customer only when the CPF does not exist in Vindi.
     */
    private function resolveCustomer(Subscription $subscription,string $code): array
    {
        $local=$subscription->customer->fresh();
        if ($local->vindi_customer_id) return $this->client->customer($local->vindi_customer_id);
        $byCode=collect($this->client->customersByCode($code))->where('code',$code)->values();
        if ($byCode->count()>1) throw new \RuntimeException('Ambiguous customer.');
        if ($byCode->isNotEmpty()) return $byCode->first();
        $cpf=preg_replace('/\D/','',(string)$local->document);
        // Archived records are ignored. A single remaining one (active or inactive) is reused. Several old duplicates
        // have no active subscription (checked before the first attempt), so a record with this system's code is opened
        // instead of guessing among them; it is found again by that code next time.
        $byCpf=collect($this->client->customersByRegistryCode($cpf))->filter(fn($customer)=>preg_replace('/\D/','',(string)($customer['registry_code']??''))===$cpf
            && ($customer['status']??'active')!=='archived')->values();
        return $byCpf->count()===1 ? $byCpf->first() : $this->client->createCustomer(['code'=>$code,'name'=>$local->name,'email'=>$local->email,'registry_code'=>$cpf]
            +array_filter(['address'=>\App\Services\CustomerAddress::vindi($local->address)]));
    }

    /** O cliente antigo já paga uma assinatura feita direto na Vindi (achado pelo CPF): cobrar de novo aqui seria cobrança em dobro. */
    private function paysOutsideSubscription(Subscription $subscription,string $prefix): bool
    {
        $local=$subscription->customer->fresh();
        $cpf=preg_replace('/\D/','',(string)$local->document);
        $ids=collect($this->client->customersByRegistryCode($cpf))
            ->filter(fn($customer)=>preg_replace('/\D/','',(string)($customer['registry_code']??''))===$cpf)->pluck('id')
            ->push($local->vindi_customer_id)->filter()->map(fn($id)=>(string)$id)->unique();
        foreach ($ids as $id) {
            foreach ($this->client->subscriptionsOfCustomer($id) as $remote) {
                if ((string)data_get($remote,'customer.id')===$id && in_array($remote['status']??null,['active','future'],true)
                    && !str_starts_with((string)($remote['code']??''),$prefix.'-')) return true;
            }
        }
        return false;
    }

    private function linkCustomer(Subscription $subscription,string $vindiCustomerId): void
    {
        DB::transaction(function() use($subscription,$vindiCustomerId) {
            $customer=Customer::lockForUpdate()->findOrFail($subscription->customer_id);
            if ($customer->vindi_customer_id && $customer->vindi_customer_id!==$vindiCustomerId) throw new \RuntimeException('Customer already linked to another provider record.');
            if (!$customer->vindi_customer_id) $customer->update(['vindi_customer_id'=>$vindiCustomerId]);
        },3);
    }

    private function validateCustomer(array $customer,Subscription $subscription): void
    {
        $linked=$subscription->customer->fresh()->vindi_customer_id;
        if (!preg_match('/^[1-9][0-9]*$/D',(string)($customer['id']??'')) || ($linked && (string)$customer['id']!==$linked)
            || preg_replace('/\D/','',(string)($customer['registry_code']??''))!==preg_replace('/\D/','',$subscription->customer->document)) throw new \RuntimeException('Provider customer mismatch.');
    }

    public function paymentUrl(string $url): string
    {
        $parts=parse_url($url);
        if (!filter_var($url,FILTER_VALIDATE_URL) || ($parts['scheme']??'')!=='https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || !in_array(strtolower($parts['host']??''),config('integrations.vindi.payment_hosts',[]),true)) throw new \RuntimeException('Untrusted payment address.');
        return $url;
    }
}

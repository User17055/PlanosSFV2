<?php

namespace App\Services\Integrations;

use App\Models\{Customer,Invoice,Subscription};
use App\Services\Domain\BillingSchedule;
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
        $lock=Cache::lock('vindi-recurring-'.$subscription->id,120);
        if (!$lock->get()) return null;
        try {
            $matches=collect($this->client->subscriptionsByCode($code))->where('code',$code)->values();
            if ($matches->count()>1) throw new IntegrationPendingException('Mais de uma assinatura Vindi com a mesma referência; conferência manual necessária.');
            $first=app(BillingSchedule::class)->firstRecurringBilling($subscription->proposal->accepted_at ?? $entry->paid_at ?? now());
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
        // Persist the subscription identity before fetching bills; a webhook can be retried.
        DB::transaction(function() use($subscription,$externalId,$account) {
            Customer::lockForUpdate()->findOrFail($subscription->customer_id);
            $current=Subscription::lockForUpdate()->findOrFail($subscription->id);
            if ($current->isTerminal() || ($current->vindi_subscription_id && $current->vindi_subscription_id!==$externalId)) throw new \RuntimeException('Contract changed during checkout.');
            $current->update(['provider_account'=>$account,'vindi_subscription_id'=>$externalId]);
        },3);
        return $externalId;
    }

    private function entryBill(Subscription $subscription,Invoice $invoice,array $map,string $code,string $method): array
    {
        if ($invoice->external_id) return $this->client->bill($invoice->external_id);
        $matches=collect($this->client->billsByCode($code))->where('code',$code)->values();
        if ($matches->count()>1) throw new \RuntimeException('Ambiguous entry bill.');
        if ($matches->isNotEmpty()) return $matches->first();
        $items=collect(data_get($this->client->plan((string)$map['plan_id']),'plan_items',[]));
        if ($items->count()!==1 || !preg_match('/^[1-9][0-9]*$/D',(string)data_get($items->first(),'product.id'))) throw new \RuntimeException('Plan product is not unambiguous.');
        return $this->client->createBill(['customer_id'=>(int)$subscription->customer->fresh()->vindi_customer_id,'code'=>$code,
            'payment_method_code'=>$method,'due_at'=>$invoice->due_at->timezone('America/Sao_Paulo')->toDateString(),
            'bill_items'=>[['product_id'=>(int)data_get($items->first(),'product.id'),'amount'=>number_format($invoice->amount_cents/100,2,'.','')]],
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
        $byCpf=collect($this->client->customersByRegistryCode($cpf))->filter(fn($customer)=>preg_replace('/\D/','',(string)($customer['registry_code']??''))===$cpf)->values();
        // Several records or an archived one need a person to decide which customer is the right one.
        if ($byCpf->count()>1) throw new \RuntimeException('More than one provider customer with this CPF.');
        if ($byCpf->isNotEmpty() && ($byCpf->first()['status']??'active')!=='active') throw new \RuntimeException('Provider customer with this CPF is not active.');
        return $byCpf->first() ?? $this->client->createCustomer(['code'=>$code,'name'=>$local->name,'email'=>$local->email,'registry_code'=>$cpf]);
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

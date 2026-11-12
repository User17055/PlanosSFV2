<?php

namespace Tests\Feature;

use App\Models\{Customer,Franchise,Invoice,Pet,Product,ProductVersion,Proposal,Subscription,User};
use App\Services\Domain\ActivationService;
use App\Services\Integrations\{IntegrationPendingException,VindiCheckoutService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Tests\TestCase;

class VindiCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Subscription $subscription;
    private User $customerUser;
    private bool $created=false;
    private string $amount='99.00';
    private string $paymentUrl='https://sandbox-app.vindi.com.br/bills/333';
    private bool $failAfterCreation=false;
    private int $subscriptionPosts=0;
    private int $customerPosts=0;
    private int $billPosts=0;
    private bool $entryCreated=false;
    private string $billStatus='pending';
    private int $remoteCustomerId=111;
    private array $cpfMatches=[];
    private array $outside=[];
    private array $items=[];
    private int $itemPosts=0;
    private ?string $nextBilling=null;
    private array $subscriptionBills=[];
    private array $remotePhones=[];

    protected function setUp(): void
    {
        parent::setUp();
        config(['plans.demo'=>false,'plans.commercial_approved'=>true,'integrations.vindi.enabled'=>true,
            'integrations.vindi.api_key'=>'test-only','integrations.vindi.allow_creation'=>true,'integrations.vindi.checkout_enabled'=>true]);
        $this->mock(ActivationService::class)->shouldReceive('activate')->andReturn([]);
        $unit=Franchise::create(['name'=>'Unit test','code'=>'TEST']);
        $customer=Customer::create(['name'=>'Tutor','document'=>'52998224725','email'=>'tutor@example.test','contact_verified_at'=>now()]);
        $this->customerUser=User::factory()->create(['role'=>'customer','customer_id'=>$customer->id,'active'=>true,'email_verified_at'=>now()]);
        $pet=Pet::create(['customer_id'=>$customer->id,'name'=>'Lua','species'=>'dog']);
        $product=Product::create(['name'=>'Plan','code'=>'TEST','category'=>'health_plan','active'=>true]);
        $version=$product->versions()->create(['version'=>1,'name'=>'Plan v1','price_cents'=>9900,'enrollment_fee_cents'=>0,
            'billing_cycle'=>'monthly','duration_months'=>12,'renewal_policy'=>'automatic','species'=>['dog'],'contract_template'=>'Test contract',
            'status'=>'published','is_demo'=>false,'published_at'=>now()])->fresh();
        $snapshot=['customer'=>$customer->toArray(),'pet'=>$pet->toArray(),'product'=>$product->toArray(),'version'=>$version->toArray()];
        $proposal=Proposal::create(['number'=>'PR-TEST','customer_id'=>$customer->id,'pet_id'=>$pet->id,'product_version_id'=>$version->id,
            'originating_franchise_id'=>$unit->id,'created_by'=>$this->customerUser->id,'category'=>'health_plan','channel'=>'site',
            'price_cents'=>9900,'snapshot'=>$snapshot,'expires_at'=>now()->addDay(),'locked_at'=>now(),'accepted_at'=>now()]);
        $this->subscription=Subscription::create(['number'=>'SF-TEST','proposal_id'=>$proposal->id,'customer_id'=>$customer->id,'pet_id'=>$pet->id,
            'product_version_id'=>$version->id,'originating_franchise_id'=>$unit->id,'category'=>'health_plan','price_cents'=>9900,
            'contract_snapshot'=>$snapshot,'locked_at'=>now(),'document_status'=>'valid']);
        $this->subscription->invoices()->create(['customer_id'=>$customer->id,'originating_franchise_id'=>$unit->id,'amount_cents'=>9900,'due_at'=>now()->addDay(),'is_initial'=>true]);
        config(['integrations.vindi.checkout_plans'=>[$version->id=>['approved'=>true,'plan_id'=>222]+$version->only(['price_cents','enrollment_fee_cents','billing_cycle','duration_months','renewal_policy'])]]);
        Http::preventStrayRequests();
        Http::fake(function($request) use($customer) {
            $remoteCustomer=['id'=>111,'code'=>'sao-francisco-tutor-'.$customer->id,'registry_code'=>$customer->document,'phones'=>$this->remotePhones];
            $remote=['id'=>444,'code'=>'sao-francisco-SF-TEST','customer'=>['id'=>$this->remoteCustomerId],'plan'=>['id'=>222],'status'=>'future',
                'next_billing_at'=>$this->nextBilling,'product_items'=>$this->items];
            $path=parse_url($request->url(),PHP_URL_PATH);
            if (preg_match('~/subscriptions/444$~',$path)) return Http::response(['subscription'=>$remote]);
            if (str_ends_with($path,'/product_items')) {
                $this->itemPosts++;
                $item=['id'=>9001+count($this->items),'status'=>'active','product'=>['id'=>$request['product_id']],'pricing_schema'=>['price'=>$request['pricing_schema']['price']]];
                $this->items[]=$item;
                return Http::response(['product_item'=>$item]);
            }
            if (preg_match('~/bills/(6\d\d)$~',$path,$id)) return Http::response(['bill'=>collect($this->subscriptionBills)->firstWhere('id',(int)$id[1])]);
            if (preg_match('~/customers/(\d+)$~',$path,$id)) return Http::response(['customer'=>collect([$remoteCustomer,...$this->cpfMatches])->firstWhere('id',(int)$id[1])]);
            if (str_ends_with($path,'/customers')) {
                if ($request->method()==='POST') { $this->customerPosts++; return Http::response(['customer'=>$remoteCustomer]); }
                return Http::response(['customers'=>str_contains(urldecode($request->url()),'registry_code')?$this->cpfMatches:[]]);
            }
            if (str_ends_with($path,'/subscriptions')) {
                if ($request->method()==='POST') {
                    $this->subscriptionPosts++;
                    $this->created=true;
                    $this->items=[['id'=>9001,'status'=>'active','product'=>['id'=>777],'pricing_schema'=>['price'=>'99.0']]];
                    $remote['product_items']=$this->items;
                    if ($this->failAfterCreation) throw new \Illuminate\Http\Client\ConnectionException('test timeout');
                    return Http::response(['subscription'=>$remote]);
                }
                if (str_contains(urldecode($request->url()),'customer_id=')) return Http::response(['subscriptions'=>[...$this->outside,...($this->created?[$remote]:[])]]);
                return Http::response(['subscriptions'=>$this->created?[$remote]:[]]);
            }
            // Monthly plans: the entry is a standalone bill (no subscription), created once and found again by its code.
            $bill=['id'=>333,'code'=>'sao-francisco-SF-TEST-entrada','subscription'=>null,'customer'=>['id'=>$this->remoteCustomerId],'amount'=>$this->amount,'url'=>$this->paymentUrl,'status'=>$this->billStatus,
                'updated_at'=>now()->toIso8601String(),'due_at'=>now()->addDay()->toIso8601String(),
                'charges'=>$this->billStatus==='paid'?[['status'=>'paid','paid_at'=>now()->toIso8601String()]]:[]];
            if (str_ends_with($path,'/plans/222')) return Http::response(['plan'=>['id'=>222,'plan_items'=>[['product'=>['id'=>777]]]]]);
            if (str_ends_with($path,'/bills/333')) return Http::response(['bill'=>$bill]);
            if (str_ends_with($path,'/bills')) {
                if ($request->method()==='POST') {
                    $this->billPosts++; $this->entryCreated=true;
                    if ($this->failAfterCreation) throw new \Illuminate\Http\Client\ConnectionException('test timeout');
                    return Http::response(['bill'=>$bill]);
                }
                if (str_contains(urldecode($request->url()),'code=')) return Http::response(['bills'=>$this->entryCreated?[$bill]:[]]);
                return Http::response(['bills'=>str_contains(urldecode($request->url()),'subscription_id=444')?$this->subscriptionBills:[]]);
            }
            throw new \RuntimeException('Unexpected request');
        });
    }

    public function test_checkout_links_initial_invoice_without_duplicate_obligation_or_confirming_payment(): void
    {
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertRedirect($this->paymentUrl);
        $this->assertDatabaseCount('invoices',1);
        $this->assertDatabaseCount('payments',0);
        $invoice=$this->subscription->invoices()->sole();
        $this->assertSame('333',$invoice->external_id);
        $this->assertSame('pending',$invoice->status);
        $this->assertNull($this->subscription->fresh()->vindi_subscription_id,'The monthly subscription waits for the paid entry.');
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertRedirect($this->paymentUrl);
        $this->assertSame(0,$this->subscriptionPosts);
        $this->assertSame(1,$this->billPosts);
    }

    public function test_monthly_entry_is_charged_now_and_installments_start_on_the_first_by_cutoff_day(): void
    {
        $start=app(\App\Services\Domain\BillingSchedule::class)->firstRecurringBilling($this->subscription->proposal->accepted_at)->format('Y-m-d');
        $this->assertSame('01',substr($start,-2));
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertRedirect($this->paymentUrl);
        Http::assertSent(fn($request)=>$request->method()==='POST' && str_ends_with($request->url(),'/bills')
            && $request['bill_items']===[['product_id'=>777,'amount'=>'99.00']] && $request['code']==='sao-francisco-SF-TEST-entrada' && !isset($request['subscription_id']));
        $this->assertSame('333',$this->subscription->invoices()->where('is_initial',true)->sole()->external_id);
        $this->actingAs($this->customerUser)->get(route('proposals.show',$this->subscription->proposal_id))->assertOk();
        $this->assertSame(0,$this->subscriptionPosts,'No subscription before the entry is paid.');
        $this->billStatus='paid';
        app(\App\Services\Integrations\VindiReconciler::class)->reconcileSubscription($this->subscription->fresh());
        Http::assertSent(fn($request)=>$request->method()==='POST' && str_ends_with($request->url(),'/subscriptions') && $request['customer_id']===111
            && str_starts_with((string)$request['start_at'],$start.'T00:00:00') && $request['billing_trigger_type']==='beginning_of_period' && !isset($request['billing_cycles']));
        $this->assertSame('444',$this->subscription->fresh()->vindi_subscription_id);
    }

    public function test_campaign_free_month_starts_the_vindi_subscription_one_month_later(): void
    {
        // Proposta assinada é travada no modelo; a campanha já vem gravada desde a abertura.
        \Illuminate\Support\Facades\DB::table('proposals')->where('id',$this->subscription->proposal_id)->update(['free_months'=>1]);
        $start=app(\App\Services\Domain\BillingSchedule::class)->firstRecurringBilling($this->subscription->proposal->accepted_at)->addMonthNoOverflow()->format('Y-m-d');
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertRedirect($this->paymentUrl);
        $this->billStatus='paid';
        app(\App\Services\Integrations\VindiReconciler::class)->reconcileSubscription($this->subscription->fresh());
        Http::assertSent(fn($request)=>$request->method()==='POST' && str_ends_with($request->url(),'/subscriptions')
            && str_starts_with((string)$request['start_at'],$start.'T00:00:00'));
    }

    public function test_scheduled_reconciliation_confirms_paid_entry_without_webhook(): void
    {
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertRedirect($this->paymentUrl);
        $this->billStatus='paid';
        app(\App\Services\Integrations\VindiReconciler::class)->reconcileSubscription($this->subscription->fresh());
        $initial=$this->subscription->invoices()->where('is_initial',true)->sole();
        $this->assertSame('paid',$initial->status);
        $this->assertDatabaseCount('invoices',1);
        $this->assertDatabaseCount('payments',1);
        $this->assertSame('444',$this->subscription->fresh()->vindi_subscription_id);
        app(\App\Services\Integrations\VindiReconciler::class)->reconcileSubscription($this->subscription->fresh());
        $this->assertSame(1,$this->subscriptionPosts,'Reconciling again never opens a second subscription.');
        $this->assertDatabaseCount('payments',1);
    }

    public function test_failed_subscription_opening_is_retried_by_reconciliation_and_reuses_existing_one(): void
    {
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertRedirect($this->paymentUrl);
        $this->billStatus='paid';
        $this->failAfterCreation=true;
        try { app(\App\Services\Integrations\VindiReconciler::class)->reconcileSubscription($this->subscription->fresh()); } catch (\Throwable) {}
        $this->assertSame('paid',$this->subscription->invoices()->where('is_initial',true)->sole()->status);
        $this->assertNull($this->subscription->fresh()->vindi_subscription_id);
        $this->failAfterCreation=false;
        app(\App\Services\Integrations\VindiReconciler::class)->reconcileSubscription($this->subscription->fresh());
        $this->assertSame('444',$this->subscription->fresh()->vindi_subscription_id);
        $this->assertSame(1,$this->subscriptionPosts,'The subscription created before the timeout is found by its code, not duplicated.');
    }

    public function test_tutor_chooses_pix_card_or_bolepix_and_the_choice_is_kept_for_monthly_bills(): void
    {
        config(['integrations.vindi.payment_methods'=>['credit_card'=>'Cartão de crédito','pix'=>'PIX','bolepix'=>'Bolepix']]);
        $this->actingAs($this->customerUser)->get(route('proposals.show',$this->subscription->proposal_id))->assertOk()
            ->assertSee('Como pagar?')->assertSee('Bolepix')->assertSee('name="payment_method" value="pix"',false);
        $this->post(route('subscriptions.checkout',$this->subscription),['payment_method'=>'boleto_inexistente'])->assertSessionHas('error');
        Http::assertNothingSent();
        $this->post(route('subscriptions.checkout',$this->subscription),['payment_method'=>'bolepix'])->assertRedirect($this->paymentUrl);
        Http::assertSent(fn($request)=>$request->method()==='POST' && str_ends_with($request->url(),'/bills') && $request['payment_method_code']==='bolepix');
        $this->post(route('subscriptions.checkout',$this->subscription),['payment_method'=>'credit_card'])->assertRedirect($this->paymentUrl);
        $this->assertSame('bolepix',$this->subscription->fresh()->payment_method_code,'Once the entry exists, the original choice is kept.');
        $this->billStatus='paid';
        app(\App\Services\Integrations\VindiReconciler::class)->reconcileSubscription($this->subscription->fresh());
        Http::assertSent(fn($request)=>$request->method()==='POST' && str_ends_with($request->url(),'/subscriptions') && $request['payment_method_code']==='bolepix');
    }

    public function test_new_cpf_creates_one_vindi_customer_and_links_it(): void
    {
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertRedirect($this->paymentUrl);
        $this->assertSame(1,$this->customerPosts);
        $this->assertSame('111',$this->subscription->customer->fresh()->vindi_customer_id);
        Http::assertSent(fn($request)=>$request->method()==='GET' && str_contains(urldecode($request->url()),'registry_code="52998224725"'));
    }

    public function test_cep_and_address_filled_here_go_to_the_vindi_customer_and_corrections_follow(): void
    {
        $customer=$this->subscription->customer;
        $customer->update(['phone'=>'(18) 99777-1234','address'=>['street'=>'Rua Tenente Nicolau Maffei','number'=>'123','complement'=>'casa 2','neighborhood'=>'Vila Furquim',
            'city'=>'Presidente Prudente','state'=>'sp','zip'=>'19010-020','formatted'=>'Rua Tenente Nicolau Maffei, 123, casa 2']]);
        $address=['street'=>'Rua Tenente Nicolau Maffei','number'=>'123','additional_details'=>'casa 2','zipcode'=>'19010020',
            'neighborhood'=>'Vila Furquim','city'=>'Presidente Prudente','state'=>'SP','country'=>'BR'];
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertRedirect($this->paymentUrl);
        Http::assertSent(fn($request)=>$request->method()==='POST' && str_ends_with($request->url(),'/customers') && $request['address']===$address
            && $request['phones']===[['phone_type'=>'mobile','number'=>'5518997771234']]);
        // O telefone já está na Vindi: a correção do endereço vai sem repetir o telefone.
        $this->remotePhones=[['id'=>5,'phone_type'=>'mobile','number'=>'5518997771234']];
        $customer->fresh()->update(['address'=>['zip'=>'19013-200','number'=>'45']+$customer->fresh()->address]);
        Http::assertSent(fn($request)=>$request->method()==='PUT' && str_ends_with($request->url(),'/customers/111')
            && $request['address']['zipcode']==='19013200' && $request['address']['number']==='45' && !isset($request['phones']));
        // Telefone trocado aqui: substitui o de lá (mesmo id), sem acrescentar outro.
        $customer->fresh()->update(['phone'=>'(18) 3222-1111']);
        Http::assertSent(fn($request)=>$request->method()==='PUT' && ($request->data()['phones']??null)===[['id'=>5,'phone_type'=>'landline','number'=>'551832221111']]);
        // Endereço incompleto (sem CEP) não é mandado pela metade.
        $this->remotePhones=[['id'=>5,'phone_type'=>'landline','number'=>'551832221111']];
        $puts=collect(Http::recorded())->filter(fn($pair)=>$pair[0]->method()==='PUT')->count();
        $customer->fresh()->update(['address'=>['formatted'=>'Sem número']]);
        $this->assertSame($puts,collect(Http::recorded())->filter(fn($pair)=>$pair[0]->method()==='PUT')->count());
    }

    public function test_cpf_already_registered_in_vindi_is_reused_instead_of_duplicated(): void
    {
        $this->remoteCustomerId=555;
        $this->cpfMatches=[['id'=>555,'code'=>null,'registry_code'=>'529.982.247-25','status'=>'active']];
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertRedirect($this->paymentUrl);
        $this->assertSame(0,$this->customerPosts);
        $this->assertSame('555',$this->subscription->customer->fresh()->vindi_customer_id);
        Http::assertSent(fn($request)=>$request->method()==='POST' && str_ends_with($request->url(),'/bills') && $request['customer_id']===555);
    }

    public function test_cpf_that_already_pays_a_subscription_made_in_vindi_is_not_charged_again(): void
    {
        $this->remoteCustomerId=555;
        $this->cpfMatches=[['id'=>555,'code'=>null,'registry_code'=>'529.982.247-25','status'=>'active']];
        $this->outside=[['id'=>900,'code'=>null,'status'=>'active','customer'=>['id'=>555],'plan'=>['id'=>428451]]];
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertSessionHas('error');
        $this->assertSame([0,0,0],[$this->customerPosts,$this->billPosts,$this->subscriptionPosts],'Nada é criado na Vindi.');
        $this->assertDatabaseCount('vindi_checkouts',0);
        $this->assertDatabaseHas('audit_logs',['action'=>'subscription.checkout_blocked_existing_vindi']);
        // A assinatura antiga já cancelada não impede o pagamento.
        $this->outside[0]['status']='canceled';
        $this->post(route('subscriptions.checkout',$this->subscription))->assertRedirect($this->paymentUrl);
        $this->assertSame(1,$this->billPosts);
    }

    public function test_old_vindi_records_without_active_plan_do_not_block_the_payment(): void
    {
        // Arquivados são ignorados; vários antigos sem assinatura ativa: abre um cadastro com o código deste sistema.
        foreach ([[['id'=>557,'registry_code'=>'52998224725','status'=>'archived']],
            [['id'=>555,'registry_code'=>'52998224725','status'=>'inactive'],['id'=>556,'registry_code'=>'529.982.247-25','status'=>'inactive'],['id'=>557,'registry_code'=>'52998224725','status'=>'archived']]] as $matches) {
            DB::table('vindi_checkouts')->delete(); Invoice::query()->update(['external_id'=>null,'payment_url'=>null]);
            Customer::whereKey($this->subscription->customer_id)->update(['vindi_customer_id'=>null]); $this->entryCreated=false;
            $this->cpfMatches=$matches; $before=$this->customerPosts;
            $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertRedirect($this->paymentUrl);
            $this->assertSame($before+1,$this->customerPosts);
            $this->assertSame('111',$this->subscription->customer->fresh()->vindi_customer_id);
        }
        // Um único cadastro antigo inativo é reaproveitado.
        DB::table('vindi_checkouts')->delete(); Invoice::query()->update(['external_id'=>null,'payment_url'=>null]);
        Customer::whereKey($this->subscription->customer_id)->update(['vindi_customer_id'=>null]); $this->entryCreated=false;
        $this->remoteCustomerId=558; $this->cpfMatches=[['id'=>558,'registry_code'=>'52998224725','status'=>'inactive']];
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertRedirect($this->paymentUrl);
        $this->assertSame(2,$this->customerPosts);
        $this->assertSame('558',$this->subscription->customer->fresh()->vindi_customer_id);
        $this->assertSame(0,$this->subscriptionPosts);
    }

    public function test_second_pet_of_the_same_plan_enters_the_same_vindi_subscription_as_one_more_item(): void
    {
        // Primeiro pet: entrada paga, assinatura 444 aberta com o item 9001.
        $first=app(\App\Services\Domain\BillingSchedule::class)->firstRecurringBilling(now());
        $this->nextBilling=$first->addDay()->toIso8601String();
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertRedirect($this->paymentUrl);
        $this->billStatus='paid';
        app(\App\Services\Integrations\VindiReconciler::class)->reconcileSubscription($this->subscription->fresh());
        $this->assertSame(['444','9001'],[$this->subscription->fresh()->vindi_subscription_id,$this->subscription->fresh()->vindi_product_item_id]);
        // Segundo pet do mesmo cliente e do mesmo plano, com a entrada já paga.
        $owner=$this->subscription->fresh();
        $pet=Pet::create(['customer_id'=>$owner->customer_id,'name'=>'Mia','species'=>'dog']);
        $proposal=Proposal::create(['number'=>'PR-TEST2','pet_id'=>$pet->id,'expires_at'=>now()->addDay(),'locked_at'=>now(),'accepted_at'=>now()]
            +$owner->proposal->only(['customer_id','product_version_id','originating_franchise_id','created_by','category','channel','price_cents','snapshot']));
        $second=Subscription::create(['number'=>'SF-TEST2','proposal_id'=>$proposal->id,'pet_id'=>$pet->id,'locked_at'=>now(),'document_status'=>'valid']
            +$owner->only(['customer_id','product_version_id','originating_franchise_id','category','price_cents','contract_snapshot']));
        $second->invoices()->create(['customer_id'=>$owner->customer_id,'originating_franchise_id'=>$owner->originating_franchise_id,'amount_cents'=>9900,
            'due_at'=>now(),'is_initial'=>true,'status'=>'paid','paid_cents'=>9900,'external_id'=>'334','provider_account'=>'central']);
        $checkout=app(VindiCheckoutService::class);
        // A próxima fatura da assinatura ainda é de um mês antes da primeira mensalidade deste pet: espera, sem criar nada.
        $this->nextBilling=$first->subMonthNoOverflow()->addDay()->toIso8601String();
        $this->assertNull($checkout->startRecurring($second));
        $this->assertSame(0,$this->itemPosts);
        $this->nextBilling=$first->addDay()->toIso8601String();
        $this->assertSame('444',$checkout->startRecurring($second));
        $this->assertSame([1,1],[$this->subscriptionPosts,$this->itemPosts],'Nenhuma assinatura nova: um item a mais na mesma.');
        Http::assertSent(fn($request)=>$request->method()==='POST' && str_ends_with($request->url(),'/product_items') && $request['subscription_id']===444
            && $request['product_id']===777 && $request['pricing_schema']===['price'=>'99.00','schema_type'=>'flat']);
        $this->assertSame(['444','9002'],[$second->fresh()->vindi_subscription_id,$second->fresh()->vindi_product_item_id]);
        // Tentativa que caiu depois de criar o item na Vindi: o item é reaproveitado, não duplicado.
        Subscription::whereKey($second->id)->update(['vindi_subscription_id'=>null,'vindi_product_item_id'=>null]);
        $checkout->startRecurring($second->fresh());
        $this->assertSame(['444','9002',1],[$second->fresh()->vindi_subscription_id,$second->fresh()->vindi_product_item_id,$this->itemPosts]);
        // A fatura mensal sai uma só, com um item por pet, e vira uma fatura local em cada contrato.
        $this->subscriptionBills=[['id'=>600,'subscription'=>['id'=>444],'customer'=>['id'=>111],'amount'=>'198.0','status'=>'paid','url'=>null,
            'updated_at'=>now()->toIso8601String(),'due_at'=>now()->toDateString(),'charges'=>[['status'=>'paid','paid_at'=>now()->toIso8601String()]],
            'bill_items'=>[['amount'=>'99.0','product_item'=>['id'=>9001]],['amount'=>'99.0','product_item'=>['id'=>9002]]]]];
        $reconciler=app(\App\Services\Integrations\VindiReconciler::class);
        $reconciler->reconcileSubscription($owner->fresh());
        $reconciler->reconcileSubscription($second->fresh());
        $monthly=Invoice::where('external_id','600')->orderBy('subscription_id')->get();
        $this->assertSame([[$owner->id,9900,'paid'],[$second->id,9900,'paid']],$monthly->map(fn($i)=>[$i->subscription_id,$i->amount_cents,$i->status])->all());
        $this->assertSame(2,\App\Models\Payment::whereIn('invoice_id',$monthly->pluck('id'))->count(),'Um pagamento por contrato, sem duplicar ao conciliar de novo.');
        // Uma consulta por rodada: a fatura vem completa na listagem, sem buscar uma a uma.
        $this->assertFalse(collect(Http::recorded())->contains(fn($pair)=>$pair[0]->method()==='GET' && str_ends_with((string)parse_url($pair[0]->url(),PHP_URL_PATH),'/bills/600')));
        // Rodada sem mudança na Vindi: nada é reaplicado, e a conferência dos dois pets continua recente.
        $audits=\App\Models\AuditLog::where('action','invoice_reconciled')->count();
        $this->travel(40)->minutes();
        $this->assertSame(0,$reconciler->reconcileSubscription($owner->fresh()));
        $this->assertSame($audits,\App\Models\AuditLog::where('action','invoice_reconciled')->count());
        $this->assertTrue($second->fresh()->financial_checked_at->gt(now()->subMinute()));
        $this->assertTrue($owner->customer->fresh()->financial_checked_at->gt(now()->subMinute()));
        // A rodada agendada passa uma vez pela assinatura da Vindi, não uma por pet.
        \Illuminate\Support\Facades\Queue::fake();
        $this->artisan('plans:reconcile')->assertSuccessful();
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ReconcileVindiSubscriptionJob::class,1);
        // Item que não é de nenhum pet: nada é aplicado.
        $this->subscriptionBills[0]['id']=601; $this->subscriptionBills[0]['bill_items'][1]['product_item']['id']=9999;
        try { $reconciler->reconcileBill('601'); $this->fail('Item sem contrato deveria parar.'); } catch (IntegrationPendingException) {}
        $this->assertSame(0,Invoice::where('external_id','601')->count());
    }

    public function test_imported_contract_from_the_old_base_never_creates_commission_or_email(): void
    {
        // Contrato da base antiga: já ligado à assinatura e ao item da Vindi, sem entrada.
        \Illuminate\Support\Facades\Notification::fake();
        $this->subscription->invoices()->delete();
        Subscription::whereKey($this->subscription->id)->update(['vindi_subscription_id'=>'444','vindi_product_item_id'=>'9001','imported_at'=>now()]);
        $this->customerUser->update(['email_verified_at'=>null]);
        // A fatura tem também o item de um pet ainda sem contrato aqui (9002): cada contrato fica só com o próprio item.
        $this->subscriptionBills=[['id'=>600,'subscription'=>['id'=>444],'customer'=>['id'=>111],'amount'=>'198.0','status'=>'paid','url'=>null,
            'updated_at'=>now()->toIso8601String(),'due_at'=>null,'charges'=>[['status'=>'paid','paid_at'=>now()->toIso8601String(),'due_at'=>'2026-03-10T00:00:00-03:00']],
            'bill_items'=>[['amount'=>'99.0','product_item'=>['id'=>9001]],['amount'=>'99.0','product_item'=>['id'=>9002]]]],
            ['id'=>601,'subscription'=>['id'=>444],'customer'=>['id'=>111],'amount'=>'99.0','status'=>'pending','url'=>null,
            'updated_at'=>now()->toIso8601String(),'due_at'=>now()->addMonth()->toDateString(),'charges'=>[],'bill_items'=>[['amount'=>'99.0','product_item'=>['id'=>9002]]]]];
        app(\App\Services\Integrations\VindiReconciler::class)->reconcileSubscription($this->subscription->fresh());
        $invoice=Invoice::where('external_id','600')->sole();
        $this->assertSame(['paid',false,9900],[$invoice->status,$invoice->is_initial,$invoice->amount_cents],'A mensalidade antiga não vira entrada.');
        $this->assertSame('2026-03-10',$invoice->due_at->timezone('America/Sao_Paulo')->toDateString(),'Sem vencimento na fatura, vale o da cobrança.');
        $this->assertSame(0,Invoice::where('external_id','601')->count(),'Fatura só do pet ainda sem contrato fica de fora, sem travar a conferência.');
        $this->assertSame(0,\App\Models\Commission::count());
        \Illuminate\Support\Facades\Notification::assertNothingSent();
        $this->assertSame(0,$this->subscriptionPosts,'A assinatura que já existe na Vindi continua a mesma.');
    }

    public function test_other_customer_cannot_issue_or_access_checkout(): void
    {
        $stranger=User::factory()->create(['role'=>'customer','active'=>true,'email_verified_at'=>now()]);
        $this->actingAs($stranger)->post(route('subscriptions.checkout',$this->subscription))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_disabled_checkout_does_not_contact_provider(): void
    {
        config(['integrations.vindi.checkout_enabled'=>false]);
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_unapproved_mapping_or_missing_valid_contract_prevents_issuance(): void
    {
        config(['integrations.vindi.checkout_plans'=>[]]);
        $this->assertFalse(app(VindiCheckoutService::class)->available($this->subscription));
        $this->subscription->update(['document_status'=>'pending']);
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_uncertain_creation_is_recovered_by_get_without_another_post(): void
    {
        $this->failAfterCreation=true;
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertSessionHas('error');
        $this->assertDatabaseHas('vindi_checkouts',['status'=>'uncertain']);
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertRedirect($this->paymentUrl);
        $this->assertSame(1,$this->billPosts,'The entry created before the timeout is found by its code, not charged twice.');
        $this->assertDatabaseCount('invoices',1);
    }

    public function test_amount_mismatch_never_exposes_payment_link_or_marks_paid(): void
    {
        $this->amount='199.00';
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertSessionHas('error');
        $this->assertNull($this->subscription->invoices()->sole()->payment_url);
        $this->assertDatabaseCount('payments',0);
        $this->assertDatabaseHas('vindi_checkouts',['status'=>'uncertain']);
    }

    public function test_untrusted_payment_host_is_rejected(): void
    {
        $this->paymentUrl='https://app.vindi.com.br.attacker.test/pay';
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertSessionHas('error');
        $this->assertNull($this->subscription->invoices()->sole()->payment_url);
    }

    public function test_active_processing_attempt_does_not_make_any_network_request(): void
    {
        DB::table('vindi_checkouts')->insert(['subscription_id'=>$this->subscription->id,'customer_id'=>$this->subscription->customer_id,
            'provider_account'=>'central','reference'=>'sao-francisco-SF-TEST','status'=>'processing','created_at'=>now(),'updated_at'=>now()]);
        $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_institutional_entry_preserves_chosen_plan_and_unavailable_code_returns_catalog(): void
    {
        $this->get(route('sales.subscribe',['code'=>'TEST']))->assertRedirect(route('register',['product_version_id'=>$this->subscription->product_version_id,'channel'=>'site']));
        $this->assertSame($this->subscription->product_version_id,session('sf_suggested_product_version_id'));
        $this->get(route('sales.subscribe',['code'=>'UNKNOWN']))->assertRedirect(route('sales'));
    }
}

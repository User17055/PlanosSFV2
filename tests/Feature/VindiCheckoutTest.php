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
            $remoteCustomer=['id'=>111,'code'=>'sao-francisco-tutor-'.$customer->id,'registry_code'=>$customer->document];
            $remote=['id'=>444,'code'=>'sao-francisco-SF-TEST','customer'=>['id'=>$this->remoteCustomerId],'plan'=>['id'=>222]];
            $path=parse_url($request->url(),PHP_URL_PATH);
            if (preg_match('~/customers/(\d+)$~',$path,$id)) return Http::response(['customer'=>collect([$remoteCustomer,...$this->cpfMatches])->firstWhere('id',(int)$id[1])]);
            if (str_ends_with($path,'/customers')) {
                if ($request->method()==='POST') { $this->customerPosts++; return Http::response(['customer'=>$remoteCustomer]); }
                return Http::response(['customers'=>str_contains(urldecode($request->url()),'registry_code')?$this->cpfMatches:[]]);
            }
            if (str_ends_with($path,'/subscriptions')) {
                if ($request->method()==='POST') {
                    $this->subscriptionPosts++;
                    $this->created=true;
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
                return Http::response(['bills'=>[]]);
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

    public function test_duplicate_or_archived_cpf_in_vindi_stops_for_manual_review(): void
    {
        foreach ([[['id'=>555,'registry_code'=>'52998224725','status'=>'active'],['id'=>556,'registry_code'=>'529.982.247-25','status'=>'active']],
            [['id'=>557,'registry_code'=>'52998224725','status'=>'archived']]] as $matches) {
            DB::table('vindi_checkouts')->delete();
            $this->cpfMatches=$matches;
            $this->actingAs($this->customerUser)->post(route('subscriptions.checkout',$this->subscription))->assertSessionHas('error');
            $this->assertDatabaseHas('vindi_checkouts',['status'=>'uncertain']);
        }
        $this->assertSame(0,$this->customerPosts);
        $this->assertSame(0,$this->subscriptionPosts);
        $this->assertNull($this->subscription->customer->fresh()->vindi_customer_id);
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

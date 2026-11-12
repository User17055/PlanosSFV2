<?php

namespace Tests\Feature;

use App\Models\{Commission,Customer,Franchise,Invoice,Payment,Pet,Product,ProductVersion,Proposal,Subscription,User};
use App\Services\Reports\DashboardService;
use Carbon\{Carbon,CarbonImmutable};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $seller;
    private User $manager;
    private Franchise $unit;
    private ProductVersion $version;
    private int $sequence=0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-22 15:00:00'); CarbonImmutable::setTestNow('2026-10-22 15:00:00');
        config(['plans.demo'=>true]);
        $this->unit=Franchise::create(['name'=>'Unidade A','code'=>'A','active'=>true]);
        $this->admin=User::factory()->create(['name'=>'Dra. Admin Teste','role'=>'central_admin','active'=>true]);
        $this->seller=User::factory()->create(['role'=>'sales','active'=>true,'franchise_id'=>$this->unit->id]);
        $this->manager=User::factory()->create(['role'=>'manager','active'=>true]);
        $this->seller->franchises()->attach($this->unit->id,['active'=>true]);
        $this->manager->franchises()->attach($this->unit->id,['active'=>true]);
        $product=Product::create(['name'=>'Plano real','code'=>'REAL','category'=>'health_plan']);
        $this->version=ProductVersion::create(['product_id'=>$product->id,'version'=>1,'name'=>'Real','price_cents'=>10000,'billing_cycle'=>'monthly','species'=>['dog'],'contract_template'=>'Condições','is_demo'=>false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); CarbonImmutable::setTestNow(); parent::tearDown();
    }

    private function sale(?Customer $customer=null,?User $creator=null,?Franchise $unit=null,array $overrides=[]): Subscription
    {
        $id=++$this->sequence; $unit??=$this->unit; $creator??=$this->seller;
        $customer??=Customer::create(['name'=>'Tutor '.$id,'address'=>['city'=>'Presidente Prudente','state'=>'SP','neighborhood'=>'Centro']]);
        $pet=Pet::create(['customer_id'=>$customer->id,'name'=>'Pet '.$id,'species'=>'dog']);
        $proposal=Proposal::create($overrides+['number'=>'P'.$id,'customer_id'=>$customer->id,'pet_id'=>$pet->id,'created_by'=>$creator->id,'originating_franchise_id'=>$unit->id,'product_version_id'=>$this->version->id,'category'=>'health_plan','channel'=>'site','price_cents'=>10000,'snapshot'=>[],'expires_at'=>now()->addDay(),'accepted_at'=>now(),'locked_at'=>now()]);
        return Subscription::create(['number'=>'S'.$id,'proposal_id'=>$proposal->id,'customer_id'=>$customer->id,'pet_id'=>$pet->id,'product_version_id'=>$this->version->id,'originating_franchise_id'=>$unit->id,'category'=>'health_plan','price_cents'=>10000,'contract_snapshot'=>[],'locked_at'=>now(),'status'=>'active']);
    }

    private function invoice(Subscription $subscription,array $overrides=[]): Invoice
    {
        return Invoice::create($overrides+['subscription_id'=>$subscription->id,'customer_id'=>$subscription->customer_id,'originating_franchise_id'=>$subscription->originating_franchise_id,'amount_cents'=>10000,'paid_cents'=>10000,'status'=>'paid','due_at'=>now(),'confirmed_at'=>now(),'reconciliation_status'=>'verified']);
    }

    private function payment(Invoice $invoice,int $cents,string $source='provider_verified',?string $date=null): void
    {
        Payment::create(['invoice_id'=>$invoice->id,'amount_cents'=>$cents,'external_transaction_id'=>uniqid('p',true),'source'=>$source,'paid_at'=>$date??now()]);
    }

    private function report(User $user,array $filters=[]): array
    {
        $service=app(DashboardService::class);
        return $service->report($user,$service->filters($user,$filters));
    }

    public function test_cash_is_reconciled_and_dated_independently_of_sales_and_demo(): void
    {
        $sale=$this->sale(null,null,null,['accepted_at'=>'2026-09-15 12:00:00']);
        $invoice=$this->invoice($sale);
        $this->payment($invoice,3000); $this->payment($invoice,2000,'demo_seed');
        $this->payment($invoice,1000,'provider_verified','2026-10-01T02:59:59Z');
        $this->payment($invoice,500,'provider_verified','2026-10-01T03:00:00Z');
        $this->payment($this->invoice($sale,['reconciliation_status'=>'uncertain']),9000);
        $this->payment($this->invoice($sale,['status'=>'refunded']),9000);
        $report=$this->report($this->admin);
        $this->assertSame(0,$report['summary']['sold_count']);
        $this->assertSame(3500,$report['summary']['received_cents']);
        $this->assertSame(3500,array_sum(array_column($report['series'],'received_cents')));
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('<h1>Oi, Admin!</h1>',false);
    }

    public function test_geography_deduplicates_tutors_and_filters_neighborhood_without_rewriting_snapshots(): void
    {
        $one=$this->sale(); $this->sale($one->customer);
        $missing=$this->sale(); $missing->customer->update(['address'=>null]);
        $report=$this->report($this->admin,['state'=>'SP','city'=>'presidente prudente','neighborhood'=>'centro']);
        $this->assertSame(2,$report['summary']['active_count']);
        $this->assertSame(1,$report['summary']['customers']);
        $this->assertSame(2,$report['summary']['sold_count']);
        $this->assertSame(1,$report['geo']['missing_address']);
        $this->assertSame(2,$report['geo']['neighborhoods'][0]['plans']);
        $this->assertSame(1,$report['geo']['neighborhoods'][0]['customers']);
        $this->assertSame(20000,$report['summary']['mrr_cents']);
    }

    public function test_sales_scope_includes_customer_owned_links_but_not_other_sellers(): void
    {
        $this->sale();
        $other=User::factory()->create(['role'=>'sales','active'=>true]);
        $this->sale(null,$other);
        $customer=User::factory()->create(['role'=>'customer','active'=>true]);
        $this->sale(null,$customer,null,['link_owner_id'=>$this->seller->id]);
        $this->sale(null,$other,null,['link_owner_id'=>$this->seller->id]);
        $report=$this->report($this->seller);
        $this->assertSame(2,$report['summary']['sold_count']);
        $this->assertCount(1,$report['ranking']);
        $this->assertSame($this->seller->id,$report['ranking'][0]['id']);
        $this->actingAs($this->seller)->get(route('dashboard',['employee_id'=>$other->id]))->assertForbidden();
    }

    public function test_manager_cannot_access_another_unit_via_filters_maps_exports_or_employee_options(): void
    {
        $this->sale();
        $other=Franchise::create(['name'=>'Unidade restrita','code'=>'B','active'=>true]);
        $staff=User::factory()->create(['role'=>'sales','active'=>true]); $staff->franchises()->attach($other->id,['active'=>true]);
        $this->sale(null,$staff,$other);
        $report=$this->report($this->manager);
        $this->assertSame(1,$report['summary']['sold_count']);
        $this->assertFalse($report['options']['employees']->contains('id',$staff->id));
        $this->actingAs($this->manager)->get(route('dashboard',['franchise_id'=>$other->id]))->assertForbidden();
        $this->get(route('dashboard.export',['employee_id'=>$staff->id]))->assertForbidden();
        $this->get(route('dashboard.map','SP'))->assertOk();
        $this->get(route('dashboard.map','XX'))->assertNotFound();
    }

    public function test_commissions_paid_in_period_include_previous_month_earnings_and_remain_separate_from_cash(): void
    {
        $s=$this->sale(); $invoice=$this->invoice($s); $this->payment($invoice,10000);
        Commission::create(['subscription_id'=>$s->id,'proposal_id'=>$s->proposal_id,'invoice_id'=>$invoice->id,'originating_franchise_id'=>$this->unit->id,'product_id'=>$this->version->product_id,'product_version_id'=>$this->version->id,'category'=>'health_plan','beneficiary_type'=>'seller','beneficiary_user_id'=>$this->seller->id,'beneficiary_name'=>$this->seller->name,'rule'=>'seller_proposal','origin_snapshot'=>[],'base_cents'=>10000,'amount_cents'=>1000,'status'=>'paid','earned_at'=>'2026-09-15 12:00:00','paid_at'=>now(),'is_demo'=>false]);
        $summary=$this->report($this->admin)['summary'];
        $this->assertSame(1000,$summary['commission_paid_cents']);
        $this->assertSame(9000,$summary['net_after_commissions_cents']);
        $this->assertSame(0,$summary['commission_payable_cents']);
    }

    public function test_customer_and_guest_cannot_access_dashboard_or_export_and_bad_date_ranges_are_rejected(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role'=>'customer','active'=>true,'email_verified_at'=>now()]))->get(route('dashboard.export'))->assertForbidden();
        $this->actingAs($this->admin)->getJson(route('dashboard',['date_from'=>'2026-10-22','date_to'=>'2026-10-01']))->assertUnprocessable();
        $this->getJson(route('dashboard',['date_from'=>'2020-01-01']))->assertUnprocessable();
        $this->get(route('dashboard.export'))->assertOk()->assertHeader('Content-Type','text/csv; charset=UTF-8');
    }

    public function test_local_trial_contracts_never_inflate_real_sales_and_neighborhood_geometry_is_optional(): void
    {
        $this->sale();
        $this->version->update(['status'=>'local_preview']);
        $this->assertSame(0,$this->report($this->admin)['summary']['sold_count']);
        $this->assertSame(1,$this->report($this->admin,['data_mode'=>'demo'])['summary']['sold_count']);
        // Presidente Prudente: divisão aproximada dos bairros (área urbana do IBGE e bairros do OpenStreetMap; o IBGE não tem os bairros da cidade).
        $this->actingAs($this->admin)->get(route('dashboard.neighborhoods','3541406'))->assertOk()->assertHeader('Content-Type','application/geo+json');
        $prudente=json_decode(file_get_contents(public_path('assets/maps/neighborhoods/3541406.json')),true);
        $this->assertTrue($prudente['approximate']);
        $this->assertContains($prudente['features'][0]['geometry']['type'],['Polygon','MultiPolygon']);
        // Cidade sem base nenhuma continua respondendo uma coleção vazia, com o ranking de bairros como alternativa.
        $this->get(route('dashboard.neighborhoods','3539806'))->assertOk()->assertJsonPath('type','FeatureCollection')->assertJsonCount(0,'features');
        $this->get(route('dashboard.neighborhoods','../bad'))->assertNotFound();
        config(['plans.demo'=>false]);
        $this->getJson(route('dashboard',['data_mode'=>'demo']))->assertUnprocessable();
    }

    public function test_map_starts_in_prudente_and_expands_as_active_plans_appear_in_new_cities(): void
    {
        $this->assertSame(['3541406'],array_column($this->report($this->admin)['geo']['map_cities'],'code'));
        $this->sale(); $outside=$this->sale();
        $outside->customer->update(['address'=>['city'=>'Santo Anastácio','state'=>'SP','neighborhood'=>'Centro']]);
        $report=$this->report($this->admin);
        $this->assertSame(2,$report['summary']['sold_count']);
        $this->assertSame(2,$report['geo']['total']);
        $this->assertCount(2,$report['geo']['cities']);
        $this->assertSame(['Presidente Prudente','Santo Anastácio'],array_column($report['geo']['map_cities'],'name'));
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('Prudente e região')->assertDontSee('map-brasil-tab')->assertDontSee('Brasil inteiro');
        $outside->update(['status'=>'awaiting_regularization']);
        $this->assertSame(['Presidente Prudente'],array_column($this->report($this->admin)['geo']['map_cities'],'name'));
        $this->assertCount(1,$this->report($this->seller)['geo']['map_cities']);
    }

    public function test_species_counts_only_distinct_pets_with_active_health_plans(): void
    {
        $this->sale();$cat=$this->sale();$cat->pet->update(['species'=>'cat']);
        $pending=$this->sale();$pending->update(['status'=>'awaiting_regularization']);
        $cancelled=$this->sale();$cancelled->update(['status'=>'cancelled','cancelled_at'=>now()]);
        $report=$this->report($this->admin);
        $this->assertSame(['dog'=>1,'cat'=>1],$report['insights']['species']);
        $this->assertSame(2,$report['summary']['active_count']);
        $this->assertSame(1,$report['summary']['cancelled_count']);
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('Cães com plano ativo')->assertSee('Gatos com plano ativo');
    }

    public function test_month_forecast_includes_future_due_invoices_and_uses_cash_date_independently(): void
    {
        $s=$this->sale(null,null,null,['accepted_at'=>'2026-09-01 12:00:00']);
        $partial=$this->invoice($s,['status'=>'partial','paid_cents'=>3000]);$this->payment($partial,3000);
        $this->invoice($s,['status'=>'pending','paid_cents'=>0,'amount_cents'=>5000,'due_at'=>'2026-10-30 15:00:00']);
        $late=$this->invoice($s,['amount_cents'=>2000,'due_at'=>'2026-09-15 15:00:00']);$this->payment($late,2000);
        $this->invoice($s,['status'=>'cancelled','amount_cents'=>80000]);
        $this->invoice($s,['status'=>'refunded','amount_cents'=>90000]);
        $this->invoice($s,['amount_cents'=>70000,'due_at'=>'2026-11-01 15:00:00']);
        $report=$this->report($this->admin);
        $this->assertSame(15000,$report['insights']['month_expected_cents']);
        $this->assertSame(12000,$report['insights']['month_open_cents']);
        $this->assertSame(5000,$report['insights']['month_received_cents']);
        $this->assertSame(0,$report['summary']['sold_count']);
        $this->assertSame(5000,$report['plans']->sole()['received_cents']);
        $this->assertSame(15000,$report['plans']->sole()['month_expected_cents']);
    }

    public function test_products_show_received_and_cancelled_and_ties_do_not_invent_a_single_winner(): void
    {
        $one=$this->sale();$this->payment($this->invoice($one),6000);
        $product=Product::create(['name'=>'Segundo plano','code'=>'SECOND','category'=>'health_plan']);
        $this->version=ProductVersion::create(['product_id'=>$product->id,'version'=>1,'name'=>'Segundo','price_cents'=>20000,'billing_cycle'=>'monthly','species'=>['dog'],'contract_template'=>'Condições','is_demo'=>false]);
        $two=$this->sale();$this->payment($this->invoice($two),4000);$two->update(['status'=>'cancelled','cancelled_at'=>now(),'cancellation_reason'=>'Solicitação do titular']);
        $ended=$this->sale(null,null,null,['accepted_at'=>'2026-09-01 12:00:00']);$ended->update(['status'=>'ended','cancelled_at'=>now()]);
        $report=$this->report($this->admin);
        $this->assertSame(1,$report['insights']['best_sales']);$this->assertCount(2,$report['insights']['best_sellers']);
        $plans=$report['plans']->keyBy('name');
        $this->assertSame(6000,$plans['Plano real']['received_cents']);$this->assertSame(4000,$plans['Segundo plano']['received_cents']);
        $this->assertSame(1,$plans['Segundo plano']['cancelled']);$this->assertSame(1,$report['summary']['cancelled_count']);
        $this->assertCount(1,$report['insights']['recent_cancelled']);
        // Variação da base antiga ("+ 2 hemogramas") e a venda nova sem "Plano" no nome contam no plano principal, numa linha só.
        $variant=Product::create(['name'=>'Plano real + 2 hemogramas (base antiga)','code'=>'BA-REAL-H','category'=>'health_plan']);
        $this->version=ProductVersion::create(['product_id'=>$variant->id,'version'=>1,'name'=>'Real + 2 hemogramas','price_cents'=>9000,'billing_cycle'=>'monthly','species'=>['dog'],'contract_template'=>'Condições','is_demo'=>false]);
        $this->sale();
        $bare=Product::create(['name'=>'Real','code'=>'SF-REAL','category'=>'health_plan']);
        $this->version=ProductVersion::create(['product_id'=>$bare->id,'version'=>1,'name'=>'Real','price_cents'=>9000,'billing_cycle'=>'monthly','species'=>['dog'],'contract_template'=>'Condições','is_demo'=>false]);
        $this->sale();
        $plans=$this->report($this->admin)['plans'];
        $this->assertSame(['Plano real'=>3,'Segundo plano'=>1],$plans->pluck('sales','name')->all());
    }

    public function test_origins_referrers_and_revenue_preserve_distinct_customer_counts(): void
    {
        $one=$this->sale(null,null,null,['acquisition_source'=>'instagram','referrer_name'=>'Maria Indicadora']);
        $this->sale($one->customer,null,null,['acquisition_source'=>'instagram','referrer_name'=>'Maria Indicadora']);
        $old=$this->sale(null,null,null,['accepted_at'=>'2026-09-01 12:00:00','acquisition_source'=>'instagram','referrer_name'=>'Maria Indicadora']);
        $this->payment($this->invoice($old),8000);
        $report=$this->report($this->admin);$origin=$report['insights']['origins']->sole();$referral=$report['insights']['referrals']->sole();
        $this->assertSame('instagram',$origin['source']);$this->assertSame(2,$origin['sales']);$this->assertSame(1,$origin['customers']);$this->assertSame(8000,$origin['received_cents']);
        $this->assertSame('Maria Indicadora',$referral['name']);$this->assertSame(2,$referral['sales']);$this->assertSame(1,$referral['customers']);$this->assertSame(8000,$referral['received_cents']);
        $this->assertSame(0,$this->report($this->admin,['acquisition_source'=>'google'])['summary']['sold_count']);
        $csv=$this->actingAs($this->admin)->get(route('dashboard.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Maria Indicadora',$csv);$this->assertStringContainsString('Recebido no período',$csv);
    }

    public function test_new_insights_do_not_expose_outside_unit_revenue_or_referrers(): void
    {
        $one=$this->sale();$this->payment($this->invoice($one),1000);
        $other=Franchise::create(['name'=>'Outra unidade','code'=>'PRIVATE','active'=>true]);
        $private=$this->sale(null,null,$other,['referrer_name'=>'Indicação privada','acquisition_source'=>'google']);
        $this->payment($this->invoice($private),99999);$private->pet->update(['species'=>'cat']);
        $report=$this->report($this->manager);
        $this->assertSame(['dog'=>1,'cat'=>0],$report['insights']['species']);
        $this->assertSame(1000,$report['insights']['month_received_cents']);
        $this->assertSame(10000,$report['insights']['month_expected_cents']);
        $this->assertCount(0,$report['insights']['referrals']);
        $this->assertFalse($report['insights']['origins']->contains('source','google'));
    }

    public function test_neighborhood_typed_without_its_type_matches_the_map_but_different_types_do_not(): void
    {
        $same=[\App\Services\Reports\DashboardService::class,'sameNeighborhood'];
        $this->assertTrue($same('Humberto Salvador','Jardim Humberto Salvador'));
        $this->assertTrue($same('Jd. Itaipu','Jardim Itaipú'));
        $this->assertFalse($same('Vila Furquim','Parque Furquim'));
        $this->assertFalse($same('Centro','Vila Furquim'));
    }

    public function test_general_plans_include_the_old_base_but_sales_and_new_plans_never_count_it(): void
    {
        $this->sale();
        $old=$this->sale();
        // Base antiga importada: começou em março de 2025 e não é venda do sistema.
        Subscription::whereKey($old->id)->update(['imported_at'=>now(),'effective_start_at'=>'2025-03-10 12:00:00']);
        $general=$this->report($this->admin);
        $this->assertSame([2,20000,1,1],[$general['summary']['active_count'],$general['summary']['mrr_cents'],$general['summary']['sold_count'],$general['summary']['proposals_count']]);
        $this->assertSame(10000,$general['summary']['active_ticket_cents']);
        $new=$this->report($this->admin,['base'=>'novos']);
        $this->assertSame([1,10000,1],[$new['summary']['active_count'],$new['summary']['mrr_cents'],$new['summary']['sold_count']]);
        // Período de um mês: os 12 meses até ele. A base antiga já estava lá no começo; o plano novo entrou em outubro.
        $growth=$general['growth'];
        $this->assertCount(12,$growth['months']);
        $this->assertSame([1,10000],[$growth['start']['active'],$growth['start']['mrr_cents']]);
        $this->assertSame(['active'=>2,'mrr_cents'=>20000,'entered'=>1,'left'=>0],array_diff_key(end($growth['months']),['month'=>1]));
        $this->assertSame([1,10000,100.0],[$growth['active_delta'],$growth['mrr_delta_cents'],$growth['active_growth']]);
        $this->assertSame([0,1],[$new['growth']['start']['active'],end($new['growth']['months'])['active']]);
        // O crescimento segue o período escolhido no filtro.
        $this->assertCount(3,$this->report($this->admin,['date_from'=>'2026-08-01'])['growth']['months']);
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('<option value="gerais" selected>Planos gerais</option>',false)->assertSee('Crescimento dos planos gerais')->assertSee('Receita mensal');
        $this->get(route('dashboard',['base'=>'novos']))->assertOk()->assertSee('Crescimento dos planos novos')->assertSee('Contratos fechados');
        $this->get(route('dashboard',['base'=>'todos']))->assertSessionHasErrors('base');
    }
}

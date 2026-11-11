<?php

namespace Tests\Feature;

use App\Models\{BenefitRule, Campaign, Customer, DigisacConnection, Franchise, Lead, Pet, Product, ProductVersion, User};
use App\Services\Domain\{BillingSchedule, CatalogService, DomainException, ProposalService};
use App\Services\Integrations\LeadDigisacAssign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    private Franchise $unit;
    private User $admin;
    private User $seller;
    private User $client;
    private Pet $pet;
    private ProductVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        config(['plans.demo' => true]);
        $this->unit = Franchise::create(['name' => 'Manoel Goulart', 'code' => 'CENTRAL', 'active' => true]);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@campanha.test', 'password' => 'Test@2026!!!', 'role' => 'central_admin', 'active' => true]);
        $this->seller = User::create(['name' => 'Vendedora', 'email' => 'vendas@campanha.test', 'password' => 'Test@2026!!!', 'role' => 'sales', 'active' => true, 'franchise_id' => $this->unit->id]);
        $this->seller->franchises()->attach($this->unit->id, ['active' => true]);
        $customer = Customer::create(['name' => 'Tutor fictício', 'document' => 'CAMP-TEST', 'originating_franchise_id' => $this->unit->id, 'contact_verified_at' => now()]);
        $this->client = User::create(['name' => 'Tutor', 'email' => 'tutor@campanha.test', 'password' => 'Test@2026!!!', 'role' => 'customer', 'customer_id' => $customer->id, 'active' => true]);
        $this->pet = Pet::create(['customer_id' => $customer->id, 'name' => 'Mel', 'species' => 'cat', 'birth_date' => now()->subYears(2)->toDateString()]);
        $product = Product::create(['name' => 'Felino', 'code' => 'CAMP', 'category' => 'health_plan']);
        $this->version = ProductVersion::create(['product_id' => $product->id, 'version' => 1, 'name' => 'Felino', 'price_cents' => 2400, 'billing_cycle' => 'monthly', 'species' => ['cat'], 'contract_template' => 'Contrato de teste.']);
        BenefitRule::create(['product_version_id' => $this->version->id, 'code' => 'CONS', 'name' => 'Consulta', 'waiting_days' => 0, 'quantity_limit' => 2, 'period_type' => 'contract_year']);
        app(CatalogService::class)->publish($this->version, $this->admin);
    }

    private function campaign(array $data = []): Campaign
    {
        return Campaign::create($data + ['name' => '1 mensalidade grátis', 'free_months' => 1, 'starts_on' => now('America/Sao_Paulo')->subDay()->toDateString(),
            'ends_on' => now('America/Sao_Paulo')->addDays(10)->toDateString(), 'whole_site' => true, 'active' => true]);
    }

    private function propose(User $actor, array $data = [])
    {
        // Um pet novo por proposta: cada pet tem um plano por categoria.
        $pet = Pet::create(['customer_id' => $this->pet->customer_id, 'name' => 'Gato '.Pet::count(), 'species' => 'cat', 'birth_date' => now()->subYears(2)->toDateString()]);
        return app(ProposalService::class)->create($data + ['customer_id' => $pet->customer_id, 'pet_id' => $pet->id, 'product_version_id' => $this->version->id,
            'originating_franchise_id' => $this->unit->id, 'channel' => $actor->role === 'customer' ? 'site' : 'link'], $actor);
    }

    public function test_site_sale_in_the_period_gets_the_whole_site_campaign_and_first_bill_moves_one_month(): void
    {
        $campaign = $this->campaign(['free_months' => 2]);
        $proposal = $this->propose($this->client);
        $this->assertSame($campaign->id, $proposal->campaign_id);
        $this->assertSame(2, $proposal->free_months);
        $schedule = app(BillingSchedule::class);
        $closed = now();
        $this->assertSame($schedule->firstRecurringBilling($closed)->addMonthsNoOverflow(2)->toDateString(), $schedule->firstRecurringBilling($closed, null, 2)->toDateString());
    }

    public function test_site_sale_without_whole_site_campaign_or_after_the_end_pays_normally(): void
    {
        $this->campaign(['whole_site' => false]);
        $this->campaign(['ends_on' => now('America/Sao_Paulo')->subDay()->toDateString()]);
        $this->campaign(['active' => false]);
        $proposal = $this->propose($this->client);
        $this->assertNull($proposal->campaign_id);
        $this->assertSame(0, $proposal->free_months);
    }

    public function test_seller_chooses_the_campaign_and_an_expired_one_is_refused(): void
    {
        $campaign = $this->campaign(['whole_site' => false]);
        $this->assertSame(0, $this->propose($this->seller)->free_months, 'Sem escolher, a venda da equipe fica sem campanha.');
        $this->assertSame(1, $this->propose($this->seller, ['campaign_id' => $campaign->id])->free_months);
        $expired = $this->campaign(['ends_on' => now('America/Sao_Paulo')->subDays(2)->toDateString(), 'starts_on' => now('America/Sao_Paulo')->subDays(5)->toDateString()]);
        $this->expectException(DomainException::class);
        $this->propose($this->seller, ['campaign_id' => $expired->id]);
    }

    public function test_only_central_admin_registers_campaigns_and_sees_who_is_exempt(): void
    {
        $this->actingAs($this->admin)->post(route('campaigns.store'), ['name' => 'Black Friday', 'free_months' => 1, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'whole_site' => 1])
            ->assertRedirect();
        $campaign = Campaign::sole();
        $this->assertTrue($campaign->whole_site);
        $this->assertTrue($campaign->active);
        $this->actingAs($this->admin)->get(route('campaigns.index'))->assertOk()->assertSee('Quem está isento')->assertSee('Black Friday');
        $this->propose($this->client);
        $this->actingAs($this->admin)->get(route('campaigns.index'))->assertOk()->assertSee('Tutor fictício')->assertSee('Site')->assertSee('1 mensalidade');
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee(route('campaigns.index'), false);
        $this->actingAs($this->seller)->get(route('campaigns.index'))->assertForbidden();
        $this->actingAs($this->admin)->post(route('campaigns.update', $campaign), ['name' => 'Black Friday', 'free_months' => 2, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30'])->assertRedirect();
        $this->assertTrue($campaign->fresh()->whole_site, 'Salvar os campos não tira a campanha principal.');
        // Só uma principal: a nova tira a marca da anterior.
        $other = $this->campaign(['name' => 'Natal', 'whole_site' => false]);
        $this->actingAs($this->admin)->post(route('campaigns.principal', $other))->assertRedirect();
        $this->assertFalse($campaign->fresh()->whole_site);
        $this->assertTrue($other->fresh()->whole_site);
        $this->actingAs($this->admin)->get(route('campaigns.index'))->assertSee('Principal · site todo')->assertSee('data-confirm', false);
        $this->actingAs($this->admin)->post(route('campaigns.archive', $other))->assertRedirect();
        $this->assertFalse($other->fresh()->active);
        $this->assertFalse($other->fresh()->whole_site);
        $this->actingAs($this->admin)->get(route('campaigns.index'))->assertSee('Campanhas arquivadas (1)');
        $this->actingAs($this->admin)->post(route('campaigns.restore', $other))->assertRedirect();
        $this->assertTrue($other->fresh()->active);
        $this->assertSame(2, $campaign->fresh()->free_months);
        $this->actingAs($this->seller)->post(route('campaigns.store'), ['name' => 'X', 'free_months' => 1, 'starts_on' => '2026-11-01'])->assertForbidden();
    }

    public function test_queue_lead_goes_to_who_pulled_the_conversation_in_digisac_only_once(): void
    {
        $juliana = User::create(['name' => 'Juliana', 'email' => 'juliana@campanha.test', 'password' => 'Test@2026!!!', 'role' => 'manager', 'active' => true, 'digisac_user_id' => 'dg-juliana']);
        $juliana->franchises()->attach($this->unit->id, ['active' => true]);
        DigisacConnection::create(['user_id' => $this->admin->id, 'base_url' => 'https://saofranciscopet.digisac.chat', 'api_token' => 'token-de-teste',
            'digisac_user_id' => 'dg-admin', 'service_id' => 'service-1', 'verified_at' => now(), 'remote_name' => 'Admin']);
        config(['digisac.allowed_hosts' => ['saofranciscopet.digisac.chat']]);
        $queue = Lead::create(['name' => 'Na fila', 'originating_franchise_id' => $this->unit->id, 'phone' => '18999990000']);
        $taken = Lead::create(['name' => 'Já de alguém', 'originating_franchise_id' => $this->unit->id, 'phone' => '18999990000', 'handled_by' => $this->admin->id]);
        Http::preventStrayRequests();
        Http::fake([
            'saofranciscopet.digisac.chat/api/v1/contacts*' => Http::response(['data' => [['id' => 'contact-1', 'serviceId' => 'service-1', 'data' => ['number' => '5518999990000']]]]),
            // Chamado antigo (antes do lead) de outra pessoa não conta; o primeiro depois dele, já encerrado, conta.
            'saofranciscopet.digisac.chat/api/v1/tickets*' => Http::response(['data' => [
                ['id' => 't-0', 'contactId' => 'contact-1', 'isOpen' => false, 'userId' => 'dg-admin', 'createdAt' => now()->subDays(40)->toIso8601String()],
                ['id' => 't-1', 'contactId' => 'contact-1', 'isOpen' => false, 'userId' => 'dg-juliana', 'createdAt' => now()->toIso8601String()],
            ]]),
        ]);

        $this->assertSame(1, app(LeadDigisacAssign::class)->run());
        $this->assertSame($juliana->id, $queue->fresh()->handled_by);
        $this->assertSame($this->admin->id, $taken->fresh()->handled_by, 'Quem já tinha o lead continua com ele.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.assigned_from_digisac', 'entity_id' => $queue->id]);
    }
}

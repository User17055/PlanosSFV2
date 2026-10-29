<?php

namespace Tests\Feature;

use App\Models\AttributionTouch;
use App\Models\BenefitRule;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Pet;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\Proposal;
use App\Models\User;
use App\Services\Attribution\AttributionData;
use App\Services\Attribution\AttributionService;
use App\Services\Attribution\MarketingLinkService;
use App\Services\Domain\CatalogService;
use App\Services\Domain\DomainException;
use App\Services\Domain\ProposalService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttributionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private User $client;

    private Franchise $central;

    private Franchise $franchise;

    private Customer $customer;

    private Pet $pet;

    private ProductVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        config(['plans.demo' => true]);
        $this->central = Franchise::create(['name' => 'Canal central', 'code' => 'CENTRAL']);
        $this->franchise = Franchise::create(['name' => 'Unidade A', 'code' => 'A']);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@attribution.test', 'password' => 'Test@2026!', 'role' => 'central_admin', 'active' => true]);
        $this->seller = User::create(['name' => 'Vendedor', 'email' => 'seller@attribution.test', 'password' => 'Test@2026!', 'role' => 'sales', 'active' => true, 'franchise_id' => $this->franchise->id]);
        $this->seller->franchises()->attach($this->franchise->id, ['active' => true]);
        $this->customer = Customer::create(['name' => 'Tutor fictício', 'document' => 'ATTR-TEST', 'originating_franchise_id' => $this->franchise->id, 'contact_verified_at' => now()]);
        $this->client = User::create(['name' => 'Tutor', 'email' => 'client@attribution.test', 'password' => 'Test@2026!', 'role' => 'customer', 'customer_id' => $this->customer->id, 'active' => true]);
        $this->pet = Pet::create(['customer_id' => $this->customer->id, 'name' => 'Luna', 'species' => 'dog', 'birth_date' => now()->subYears(3)->toDateString()]);
        $p = Product::create(['name' => 'Plano', 'code' => 'ATTR', 'category' => 'health_plan']);
        $this->version = ProductVersion::create(['product_id' => $p->id, 'version' => 1, 'name' => 'Plano', 'price_cents' => 10000, 'billing_cycle' => 'monthly', 'species' => ['dog'], 'contract_template' => 'Contrato de teste.']);
        BenefitRule::create(['product_version_id' => $this->version->id, 'code' => 'CONS', 'name' => 'Consulta', 'waiting_days' => 0, 'quantity_limit' => 2, 'period_type' => 'contract_year']);
        app(CatalogService::class)->publish($this->version, $this->admin);
    }

    private function link(array $data = []): array
    {
        return app(MarketingLinkService::class)->create($data + ['name' => 'Campanha de teste', 'originating_franchise_id' => $this->franchise->id, 'owner_id' => $this->seller->id, 'product_version_id' => $this->version->id, 'acquisition_source' => 'shared_link'], $this->admin);
    }

    private function request(string $url = 'http://localhost/', ?Store $session = null): Request
    {
        $session ??= new Store('attribution-test', new ArraySessionHandler(120));
        if (! $session->isStarted()) {
            $session->start();
        }
        $request = Request::create($url);
        $request->setLaravelSession($session);

        return $request;
    }

    private function proposal(array $data = [], ?AttributionData $attribution = null, ?User $actor = null): Proposal
    {
        return app(ProposalService::class)->create($data + ['customer_id' => $this->customer->id, 'pet_id' => $this->pet->id, 'product_version_id' => $this->version->id, 'originating_franchise_id' => $this->franchise->id, 'channel' => 'site'], $actor ?? $this->client, $attribution);
    }

    public function test_shareable_token_can_be_copied_again_without_rotation_and_is_not_exposed_by_serialization(): void
    {
        ['link' => $link,'token' => $token] = $this->link();
        $service = app(MarketingLinkService::class);
        $this->assertSame($token, $service->urlToken($link->fresh(), $this->seller));
        $this->assertSame($token, $service->urlToken($link->fresh(), $this->admin));
        $this->assertSame($link->id, $service->resolve($token)->id);
        $this->assertArrayNotHasKey('token_hash', $link->toArray());
        $this->assertArrayNotHasKey('token_encrypted', $link->toArray());
        $this->assertNotSame($token, DB::table('marketing_links')->where('id', $link->id)->value('token_encrypted'));
    }

    public function test_captured_first_and_last_touch_persist_without_internal_navigation_erasing_campaign(): void
    {
        $service = app(AttributionService::class);
        $firstRequest = $this->request();
        $first = $service->capture($firstRequest);
        ['link' => $link] = $this->link(['acquisition_source' => 'instagram', 'campaign_name' => 'Outubro', 'utm_campaign' => 'outubro']);
        $linkedRequest = $this->request('http://localhost/l/token?owner_id=9999&franchise_id=9999&gclid=secret', $firstRequest->session());
        $last = $service->capture($linkedRequest, $link);
        $ordinary = $this->request('http://localhost/contratar', $firstRequest->session());
        $this->assertSame($last->id, $service->capture($ordinary)->id);
        $data = $service->proposalData($ordinary, [], $this->client);
        $this->assertSame('instagram', $data->attributes['acquisition_source']);
        $this->assertSame($first->id, $data->attributes['first_touch_id']);
        $this->assertSame($last->id, $data->attributes['last_touch_id']);
        $this->assertSame($this->seller->id, $data->attributes['link_owner_id']);
        $this->assertSame($this->franchise->id, $data->originatingFranchiseId);
        $this->assertSame(2, AttributionTouch::count());
        $this->assertSame(0, Proposal::count());
    }

    public function test_referrer_stores_only_host_and_query_capture_ignores_click_tokens(): void
    {
        $request = $this->request('http://localhost/?utm_source=instagram&utm_medium=social&utm_content=a%40example.com&gclid=secret&fbclid=secret');
        $request->headers->set('referer', 'https://person:password@l.instagram.com/private/person@example.com?cpf=123&token=secret');
        $touch = app(AttributionService::class)->capture($request);
        $this->assertSame('l.instagram.com', $touch->referrer_host);
        $this->assertSame('instagram', $touch->acquisition_source);
        $this->assertNull($touch->utm_content);
        $this->assertStringNotContainsString('secret', json_encode($touch->toArray()));
        $this->assertStringNotContainsString('person@example.com', json_encode($touch->toArray()));
        $this->assertArrayNotHasKey('session_key_hash', $touch->toArray());
    }

    public function test_employee_preview_is_not_inherited_by_next_manual_customer_proposal(): void
    {
        ['link' => $link] = $this->link();
        $request = $this->request();
        $service = app(AttributionService::class);
        $service->capture($request, $link);
        $data = $service->proposalData($request, ['acquisition_source' => 'referral', 'referrer_name' => 'Pessoa que indicou'], $this->seller);
        $this->assertSame('referral', $data->attributes['acquisition_source']);
        $this->assertNull($data->attributes['marketing_link_id']);
        $this->assertNull($data->attributes['first_touch_id']);
        $this->assertNull($data->originatingFranchiseId);
    }

    public function test_customer_cannot_set_arbitrary_link_or_third_party_customer_identifiers(): void
    {
        ['link' => $link] = $this->link();
        $this->expectException(DomainException::class);
        app(AttributionService::class)->proposalData($this->request(), ['marketing_link_id' => $link->id], $this->client);
    }

    public function test_public_link_unit_is_server_owned_and_unlinked_site_uses_central_channel(): void
    {
        ['link' => $link] = $this->link();
        $request = $this->request('http://localhost/?originating_franchise_id='.$this->central->id.'&owner_id='.$this->admin->id);
        $service = app(AttributionService::class);
        $service->capture($request, $link);
        $p = $this->proposal([], $service->proposalData($request, [], $this->client));
        $this->assertSame($this->franchise->id, $p->originating_franchise_id);
        $this->assertSame($this->seller->id, $p->link_owner_id);
        $unlinked = $this->request();
        $service->capture($unlinked);
        $direct = $this->proposal([], $service->proposalData($unlinked, [], $this->client));
        $this->assertSame($this->central->id, $direct->originating_franchise_id);
        $this->assertSame('direct', $direct->acquisition_source);
    }

    public function test_accepted_attribution_is_copied_frozen_and_does_not_rewrite_contract_snapshot(): void
    {
        ['link' => $link] = $this->link(['referrer_name' => 'Maria', 'campaign_name' => 'Compartilhamento']);
        $request = $this->request();
        $service = app(AttributionService::class);
        $service->capture($request, $link);
        $p = $this->proposal([], $service->proposalData($request, [], $this->client));
        $original = $p->snapshot;
        $s = app(ProposalService::class)->accept($p, $this->client, ['accepted' => true]);
        $this->assertSameIgnoringKeyOrder($p->attribution_snapshot, $s->attribution_snapshot);
        $this->assertSame($original, $s->contract_snapshot);
        $this->assertSame('shared_link', $s->acquisition_source);
        $this->assertSame($this->seller->id, $s->link_owner_id);
        $this->expectException(QueryException::class);
        DB::table('subscriptions')->where('id', $s->id)->update(['acquisition_source' => 'direct', 'link_owner_id' => null]);
    }

    public function test_accepted_proposal_cannot_change_attribution_even_through_direct_sql(): void
    {
        $data = app(AttributionService::class)->proposalData($this->request(), ['acquisition_source' => 'referral', 'referrer_name' => 'Maria'], $this->client);
        $p = $this->proposal([], $data);
        app(ProposalService::class)->accept($p, $this->client, ['accepted' => true]);
        $this->expectException(QueryException::class);
        DB::table('proposals')->where('id', $p->id)->update(['referrer_name' => 'Outra pessoa']);
    }

    public function test_historical_origin_remains_unknown_and_is_not_inferred_from_hiring_channel(): void
    {
        $p = $this->proposal()->fresh();
        $this->assertSame('site', $p->channel);
        $this->assertSame('unknown', $p->acquisition_source);
        $this->assertNull($p->attribution_snapshot);
        $s = app(ProposalService::class)->accept($p, $this->client, ['accepted' => true]);
        $this->assertSame('unknown', $s->acquisition_source);
        $this->assertNull($s->attribution_snapshot);
    }

    public function test_revocation_stops_public_resolution_but_authorized_owner_can_copy_existing_token(): void
    {
        ['link' => $link,'token' => $token] = $this->link();
        $service = app(MarketingLinkService::class);
        $service->revoke($link, $this->seller);
        $this->assertSame($token, $service->urlToken($link->fresh(), $this->seller));
        $this->expectException(DomainException::class);
        $service->resolve($token);
    }

    public function test_salesperson_cannot_assign_link_to_another_employee_and_franchise(): void
    {
        $this->expectException(DomainException::class);
        app(MarketingLinkService::class)->create(['name' => 'Tentativa', 'originating_franchise_id' => $this->central->id, 'owner_id' => $this->admin->id], $this->seller);
    }

    public function test_known_referrer_is_recorded_with_authorized_server_label_instead_of_browser_name(): void
    {
        ['link' => $link] = $this->link(['acquisition_source' => 'referral', 'referrer_customer_id' => $this->customer->id, 'referrer_name' => 'Nome enviado pelo navegador']);
        $this->assertSame($this->customer->name, $link->referrer_name);
    }

    public function test_demoted_link_owner_cannot_keep_assigning_new_public_acquisitions(): void
    {
        ['link' => $link,'token' => $token] = $this->link();
        $this->assertTrue(app(MarketingLinkService::class)->isUsable($link));
        $this->seller->update(['role' => 'finance']);
        $this->expectException(DomainException::class);
        app(MarketingLinkService::class)->resolve($token);
    }

    public function test_customer_cannot_replace_known_link_referrer_name_while_preserving_its_id(): void
    {
        ['link' => $link] = $this->link(['acquisition_source' => 'referral', 'referrer_customer_id' => $this->customer->id]);
        $request = $this->request();
        $service = app(AttributionService::class);
        $service->capture($request, $link);
        $data = $service->proposalData($request, ['referrer_name' => 'Outra pessoa enviada pelo navegador'], $this->client);
        $this->assertSame($this->customer->id, $data->attributes['referrer_customer_id']);
        $this->assertSame($this->customer->name, $data->attributes['referrer_name']);
        $this->assertSame($this->customer->name, $data->attributes['attribution_snapshot']['referrer_name']);
    }
}

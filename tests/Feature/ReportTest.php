<?php

namespace Tests\Feature;

use App\Models\{AttributionTouch, ContractDocument, Customer, Franchise, Invoice, MarketingLink, Payment, Pet, Product, ProductVersion, Proposal, Subscription, User};
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private Franchise $a;
    private Franchise $b;
    private User $admin;
    private User $manager;
    private User $sellerA;
    private User $sellerB;
    private ProductVersion $version;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-19T12:00:00-03:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19T12:00:00-03:00'));
        $this->a = Franchise::create(['name' => 'Franquia autorizada', 'code' => 'REPORT-A']);
        $this->b = Franchise::create(['name' => 'Franquia restrita', 'code' => 'REPORT-B']);
        $this->admin = User::factory()->create(['role' => 'central_admin', 'active' => true]);
        $this->manager = User::factory()->create(['role' => 'manager', 'active' => true]);
        $this->sellerA = User::factory()->create(['name' => 'Vendedor autorizado', 'role' => 'sales', 'active' => true]);
        $this->sellerB = User::factory()->create(['name' => 'Vendedor restrito', 'role' => 'sales', 'active' => true]);
        $this->manager->franchises()->attach($this->a->id, ['active' => true]);
        $this->manager->franchises()->attach($this->b->id, ['active' => false]);
        $product = Product::create(['name' => 'Produto para relatórios', 'code' => 'REPORT', 'category' => 'health_plan']);
        $this->version = ProductVersion::create(['product_id' => $product->id, 'version' => 1, 'name' => 'Versão de teste',
            'price_cents' => 9900, 'billing_cycle' => 'monthly', 'species' => ['dog'], 'contract_template' => 'Condições de teste.']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_manager_scope_is_applied_to_rows_totals_options_links_and_csv(): void
    {
        $this->proposal($this->a, $this->sellerA, ['name' => 'Tutor autorizado']);
        $this->proposal($this->b, $this->sellerB, ['name' => 'Tutor restrito']);
        $inactive = Franchise::create(['name' => 'Franquia inativa', 'code' => 'REPORT-OFF', 'active' => false]);
        $this->manager->franchises()->attach($inactive->id, ['active' => true]);
        $this->proposal($inactive, $this->sellerB, ['name' => 'Tutor unidade desligada']);
        $this->marketingLink($this->a, $this->sellerA, 'Link autorizado');
        $this->marketingLink($this->b, $this->sellerB, 'Link restrito');
        $response = $this->actingAs($this->manager)->get(route('reports.index', $this->dates()))->assertOk();
        $response->assertViewHas('summary', fn ($summary) => $summary['proposals'] === 1);
        $response->assertSee('Tutor autorizado')->assertDontSee('Tutor restrito')->assertDontSee('Vendedor restrito')->assertDontSee('Link restrito')->assertDontSee('Tutor unidade desligada');
        $csv = $this->get(route('reports.export', $this->dates()))->assertOk()->streamedContent();
        $this->assertStringContainsString('Tutor autorizado', $csv);
        $this->assertStringNotContainsString('Tutor restrito', $csv);
        $this->assertStringNotContainsString('Tutor unidade desligada', $csv);
        $this->get(route('reports.index', $this->dates() + ['franchise_id' => $this->b->id]))->assertForbidden();
        $this->get(route('reports.export', $this->dates() + ['franchise_id' => $this->b->id]))->assertForbidden();
        $this->actingAs($this->admin)->get(route('reports.index', $this->dates()))->assertOk()
            ->assertViewHas('summary', fn ($summary) => $summary['proposals'] === 3);
    }

    public function test_accepted_activated_and_reconciled_receipts_are_independent_without_join_duplication(): void
    {
        $accepted = $this->proposal($this->a, $this->sellerA, ['accepted' => true]);
        $this->subscription($accepted);
        $activeProposal = $this->proposal($this->a, $this->sellerA, ['accepted' => true]);
        $active = $this->subscription($activeProposal, ['status' => 'active', 'activated_at' => now(), 'document_status' => 'valid']);
        $this->proposal($this->a, $this->sellerA);
        foreach ([1, 2] as $version) ContractDocument::create(['subscription_id' => $active->id, 'proposal_id' => $activeProposal->id,
            'kind' => 'final_contract', 'signature_method' => 'digital', 'status' => 'valid', 'storage_path' => 'not-used-in-reports-'.$version.'.pdf',
            'sha256' => str_repeat('0', 64), 'size_bytes' => 1, 'version' => $version, 'accepted_at' => now(), 'validated_at' => now()]);
        $invoice = $this->invoice($active, 3000, true);
        $this->payment($invoice, 1000, 'vindi_api');
        $this->payment($invoice, 1000, 'vindi_api');
        $this->payment($invoice, 1000, 'vindi_api', '2026-09-30T10:00:00Z');
        $demo = $this->invoice($active, 500);
        $this->payment($demo, 500, 'demo_finance');
        $uncertain = $this->invoice($active, 333, false, 'uncertain');
        $this->payment($uncertain, 333, 'vindi_api');
        $response = $this->actingAs($this->admin)->get(route('reports.index', $this->dates()))->assertOk();
        $response->assertViewHas('summary', fn ($s) => $s['proposals'] === 3 && $s['accepted'] === 2 && $s['activated'] === 1
            && $s['initial_paid'] === 1 && $s['received_cents'] === 2000 && $s['demo_received_cents'] === 500);
        $response->assertViewHas('proposals', fn ($rows) => $rows->firstWhere('id', $activeProposal->id)['received_cents'] === 2000);
        $csvRows = $this->csvRows($this->get(route('reports.export', $this->dates()))->assertOk()->streamedContent());
        $this->assertCount(4, $csvRows); // One header and one row per proposal, not per payment/document.
        $values = collect(array_slice($csvRows, 1))->first(fn ($row) => $row[0] === $activeProposal->number);
        $this->assertSame('20,00', $values[20]);
        $this->assertSame('5,00', $values[21]);
    }

    public function test_combined_filters_match_source_campaign_link_product_seller_and_status(): void
    {
        $link = $this->marketingLink($this->a, $this->sellerB, 'Link da campanha', 'instagram', 'Outubro');
        $matching = $this->proposal($this->a, $this->sellerA, ['accepted' => true, 'acquisition_source' => 'instagram',
            'campaign_name' => 'Outubro', 'marketing_link_id' => $link->id, 'link_owner_id' => $this->sellerB->id, 'channel' => 'link']);
        $this->subscription($matching);
        $this->proposal($this->a, $this->sellerA, ['acquisition_source' => 'instagram', 'campaign_name' => 'Outra campanha', 'channel' => 'link']);
        $filters = $this->dates() + ['franchise_id' => $this->a->id, 'salesperson_id' => $this->sellerA->id,
            'product_id' => $this->version->product_id, 'channel' => 'link', 'acquisition_source' => 'instagram',
            'marketing_link_id' => $link->id, 'campaign' => 'Outubro', 'status' => 'awaiting_regularization'];
        $this->actingAs($this->admin)->get(route('reports.index', $filters))->assertOk()
            ->assertViewHas('summary', fn ($s) => $s['proposals'] === 1)
            ->assertViewHas('proposals', fn ($rows) => $rows->first()['seller'] === 'Vendedor autorizado' && $rows->first()['link_owner'] === 'Vendedor restrito');
    }

    public function test_historical_unknown_source_is_not_inferred_from_the_link_channel_and_csv_is_safe(): void
    {
        $proposal = $this->proposal($this->a, $this->sellerA, ['name' => '=HYPERLINK("https://example.test","X")', 'channel' => 'link']);
        $response = $this->actingAs($this->admin)->get(route('reports.index', $this->dates() + ['acquisition_source' => 'unknown']))->assertOk();
        $response->assertViewHas('proposals', fn ($rows) => $rows->first()['id'] === $proposal->id && $rows->first()['source'] === 'Não informado'
            && $rows->first()['campaign'] === 'Não informado' && $rows->first()['link_owner'] === 'Não informado');
        $csv = $this->get(route('reports.export', $this->dates()))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = $this->csvRows($csv);
        $this->assertSame("'=HYPERLINK(\"https://example.test\",\"X\")", $rows[1][2]);
        $this->assertSame('Não informado', $rows[1][9]);
    }

    public function test_calendar_boundaries_use_sao_paulo_and_dates_are_bounded(): void
    {
        $this->proposal($this->a, $this->sellerA, ['created_at' => '2026-10-01T02:59:59Z']);
        $this->proposal($this->a, $this->sellerA, ['created_at' => '2026-10-01T03:00:00Z']);
        $this->proposal($this->a, $this->sellerA, ['created_at' => '2026-10-20T02:59:59Z']);
        $this->proposal($this->a, $this->sellerA, ['created_at' => '2026-10-20T03:00:00Z']);
        $this->actingAs($this->admin)->get(route('reports.index', $this->dates()))->assertOk()
            ->assertViewHas('summary', fn ($s) => $s['proposals'] === 2);
        $this->getJson(route('reports.index', ['date_from' => '2025-01-01', 'date_to' => '2026-10-19']))->assertUnprocessable();
        $this->getJson(route('reports.index', ['date_from' => '2026-10-19', 'date_to' => '2026-10-01']))->assertUnprocessable();
        $this->getJson(route('reports.index', ['date_from' => '2026-10-01', 'date_to' => '2026-10-20']))->assertUnprocessable();
    }

    public function test_link_accesses_are_events_not_people_and_include_links_without_contracts(): void
    {
        $link = $this->marketingLink($this->a, $this->sellerA, 'Sem contratação');
        $restricted = $this->marketingLink($this->b, $this->sellerB, 'Sem acesso à unidade');
        foreach ([$link, $link, $restricted] as $entry) AttributionTouch::create(['marketing_link_id' => $entry->id,
            'session_key_hash' => str_repeat('a', 64), 'acquisition_source' => 'shared_link', 'owner_id' => $entry->owner_id,
            'originating_franchise_id' => $entry->originating_franchise_id, 'touched_at' => now(), 'metadata_snapshot' => []]);
        $this->actingAs($this->manager)->get(route('reports.index', $this->dates()))->assertOk()
            ->assertViewHas('linkReport', fn ($report) => $report['accesses'] === 2 && $report['links']->total() === 1
                && $report['links']->first()->access_count === 2 && $report['links']->first()->proposal_count === 0)
            ->assertSee('não comprovam pessoas únicas');
    }

    public function test_customers_sales_and_revoked_memberships_cannot_read_report_data(): void
    {
        $this->proposal($this->a, $this->sellerA, ['name' => 'Tutor protegido']);
        foreach (['customer', 'sales', 'operator'] as $role) {
            $user = User::factory()->create(['role' => $role, 'active' => true]);
            $this->actingAs($user)->get(route('reports.index', $this->dates()))->assertForbidden();
            $this->get(route('reports.export', $this->dates()))->assertForbidden();
        }
        $finance = User::factory()->create(['role' => 'finance', 'active' => true]);
        $finance->franchises()->attach($this->a->id, ['active' => true]);
        $this->actingAs($finance)->get(route('reports.index', $this->dates()))->assertOk()->assertSee('Tutor protegido');
        $this->manager->franchises()->updateExistingPivot($this->a->id, ['active' => false]);
        $this->actingAs($this->manager)->get(route('reports.index', $this->dates()))->assertOk()
            ->assertViewHas('summary', fn ($s) => $s['proposals'] === 0)->assertDontSee('Tutor protegido');
    }

    public function test_expired_link_is_labelled_expired_while_its_historical_accesses_remain(): void
    {
        $link = $this->marketingLink($this->a, $this->sellerA, 'Link com prazo encerrado');
        $link->update(['expires_at' => now()->subMinute()]);
        AttributionTouch::create(['marketing_link_id' => $link->id, 'session_key_hash' => str_repeat('a', 64),
            'acquisition_source' => 'shared_link', 'owner_id' => $link->owner_id, 'originating_franchise_id' => $this->a->id,
            'touched_at' => now()->subHour(), 'metadata_snapshot' => []]);
        $this->actingAs($this->manager)->get(route('reports.index', $this->dates()))->assertOk()
            ->assertSee('Link expirado')->assertDontSee('Link ativo')
            ->assertViewHas('linkReport', fn ($report) => $report['accesses'] === 1 && $report['links']->first()->access_count === 1);
    }

    public function test_link_owner_filter_is_separate_from_proposal_creator_and_scopes_csv_and_accesses(): void
    {
        $chosen = $this->marketingLink($this->a, $this->sellerA, 'Link escolhido');
        $other = $this->marketingLink($this->a, $this->sellerB, 'Outro responsável');
        $this->proposal($this->a, $this->sellerB, ['name' => 'Tutor do link escolhido', 'marketing_link_id' => $chosen->id,
            'link_owner_id' => $this->sellerA->id]);
        $this->proposal($this->a, $this->sellerB, ['name' => 'Tutor do outro link', 'marketing_link_id' => $other->id,
            'link_owner_id' => $this->sellerB->id]);
        foreach ([$chosen, $other] as $link) AttributionTouch::create(['marketing_link_id' => $link->id,
            'session_key_hash' => hash('sha256', (string) $link->id), 'acquisition_source' => 'shared_link',
            'owner_id' => $link->owner_id, 'originating_franchise_id' => $this->a->id, 'touched_at' => now(), 'metadata_snapshot' => []]);
        $filters = $this->dates() + ['link_owner_id' => $this->sellerA->id, 'salesperson_id' => $this->sellerB->id];
        $this->actingAs($this->manager)->get(route('reports.index', $filters))->assertOk()
            ->assertViewHas('summary', fn ($summary) => $summary['proposals'] === 1)
            ->assertViewHas('linkReport', fn ($report) => $report['accesses'] === 1 && $report['links']->total() === 1)
            ->assertSee('Tutor do link escolhido')->assertDontSee('Tutor do outro link');
        $csv = $this->get(route('reports.export', $filters))->assertOk()->streamedContent();
        $this->assertStringContainsString('Tutor do link escolhido', $csv);
        $this->assertStringNotContainsString('Tutor do outro link', $csv);
    }

    private function dates(): array
    {
        return ['date_from' => '2026-10-01', 'date_to' => '2026-10-19'];
    }

    private function proposal(Franchise $franchise, User $creator, array $attributes = []): Proposal
    {
        $number = ++$this->sequence;
        $customer = Customer::create(['name' => $attributes['name'] ?? 'Tutor relatório '.$number, 'document' => 'REPORT-'.$number]);
        $pet = Pet::create(['customer_id' => $customer->id, 'name' => 'Pet relatório '.$number, 'species' => 'dog']);
        $snapshot = ['customer' => $customer->toArray(), 'pet' => $pet->toArray(), 'product' => $this->version->product->toArray(), 'version' => $this->version->toArray()];
        $accepted = $attributes['accepted'] ?? false;
        unset($attributes['name'], $attributes['accepted']);
        return Proposal::create($attributes + ['number' => 'REPORT-'.$number, 'customer_id' => $customer->id, 'pet_id' => $pet->id,
            'product_version_id' => $this->version->id, 'originating_franchise_id' => $franchise->id, 'created_by' => $creator->id,
            'category' => 'health_plan', 'channel' => 'site', 'price_cents' => 9900, 'snapshot' => $snapshot,
            'expires_at' => now()->addDay(), 'accepted_at' => $accepted ? now() : null, 'locked_at' => $accepted ? now() : null]);
    }

    private function subscription(Proposal $proposal, array $attributes = []): Subscription
    {
        return Subscription::create($attributes + ['number' => 'SUB-'.$proposal->id, 'proposal_id' => $proposal->id,
            'customer_id' => $proposal->customer_id, 'pet_id' => $proposal->pet_id, 'product_version_id' => $proposal->product_version_id,
            'originating_franchise_id' => $proposal->originating_franchise_id, 'category' => $proposal->category,
            'contract_snapshot' => $proposal->snapshot, 'price_cents' => $proposal->price_cents, 'locked_at' => now()]);
    }

    private function invoice(Subscription $s, int $amount, bool $initial = false, string $reconciliation = 'verified'): Invoice
    {
        return Invoice::create(['subscription_id' => $s->id, 'customer_id' => $s->customer_id, 'originating_franchise_id' => $s->originating_franchise_id,
            'due_at' => now(), 'amount_cents' => $amount, 'paid_cents' => $amount, 'status' => 'paid', 'is_initial' => $initial,
            'reconciliation_status' => $reconciliation, 'confirmed_at' => now()]);
    }

    private function payment(Invoice $invoice, int $amount, string $source, ?string $date = null): Payment
    {
        return Payment::create(['invoice_id' => $invoice->id, 'external_transaction_id' => Str::uuid()->toString(),
            'amount_cents' => $amount, 'source' => $source, 'paid_at' => $date ?? now()]);
    }

    private function marketingLink(Franchise $franchise, User $owner, string $name, string $source = 'shared_link', ?string $campaign = null): MarketingLink
    {
        $token = Str::random(64);
        return MarketingLink::create(['name' => $name, 'owner_id' => $owner->id, 'created_by' => $owner->id,
            'originating_franchise_id' => $franchise->id, 'token_hash' => hash('sha256', $token), 'token_encrypted' => $token,
            'acquisition_source' => $source, 'campaign_name' => $campaign, 'product_version_id' => $this->version->id]);
    }

    private function csvRows(string $csv): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, ';', '"', '')) !== false) $rows[] = $row;
        fclose($stream);
        return $rows;
    }
}

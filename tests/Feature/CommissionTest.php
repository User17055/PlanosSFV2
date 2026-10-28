<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BenefitRule;
use App\Models\Commission;
use App\Models\CommissionRate;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Invoice;
use App\Models\Pet;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\Proposal;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Attribution\AttributionData;
use App\Services\Domain\CatalogService;
use App\Services\Domain\CommissionService;
use App\Services\Domain\DomainException;
use App\Services\Domain\FinancialService;
use App\Services\Domain\ProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommissionTest extends TestCase
{
    use RefreshDatabase;

    private Franchise $a;
    private Franchise $b;
    private User $admin;
    private User $seller;
    private User $finance;
    private Customer $customer;
    private Pet $pet;
    private User $client;
    private ProductVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        config(['plans.demo' => true, 'plans.financial_max_age_minutes' => 1440]);
        Storage::fake('local');
        Franchise::create(['name' => 'Canal central', 'code' => 'CENTRAL']);
        $this->a = Franchise::create(['name' => 'Unidade A', 'code' => 'A']);
        $this->b = Franchise::create(['name' => 'Unidade B', 'code' => 'B']);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@commission.test', 'password' => 'Example123!', 'role' => 'central_admin', 'active' => true]);
        $this->seller = $this->staff('Vendedora Ana', 'sales', $this->a);
        $this->finance = $this->staff('Financeiro A', 'finance', $this->a);
        $this->customer = Customer::create(['name' => 'Tutor comissão', 'document' => 'DEMO-COM', 'email' => 'tutor@commission.test', 'contact_verified_at' => now(),
            'originating_franchise_id' => $this->a->id, 'financial_verification_status' => 'verified', 'financial_checked_at' => now()]);
        $this->pet = Pet::create(['customer_id' => $this->customer->id, 'name' => 'Luna', 'species' => 'dog', 'birth_date' => now()->subYears(4)->toDateString()]);
        $this->client = User::create(['name' => 'Tutor', 'email' => 'client@commission.test', 'password' => 'Example123!', 'role' => 'customer', 'customer_id' => $this->customer->id, 'active' => true, 'email_verified_at' => now()]);
        $this->version = $this->makeVersion();
        CommissionRate::create(['product_id' => $this->version->product_id, 'rate_basis_points' => 1000, 'effective_from' => now()->subDay()]);
    }

    private function staff(string $name, string $role, Franchise $franchise): User
    {
        $user = User::create(['name' => $name, 'email' => Str::slug($name).'@commission.test', 'password' => 'Example123!', 'role' => $role, 'active' => true, 'franchise_id' => $franchise->id]);
        $user->franchises()->attach($franchise->id, ['active' => true]);

        return $user;
    }

    private function makeVersion(): ProductVersion
    {
        $p = Product::create(['name' => 'Plano teste', 'code' => Str::uuid(), 'category' => 'health_plan']);
        $v = ProductVersion::create(['product_id' => $p->id, 'version' => 1, 'name' => 'Plano teste', 'price_cents' => 14990, 'billing_cycle' => 'monthly',
            'renewal_policy' => 'none', 'species' => ['dog', 'cat'], 'min_age_months' => 2, 'max_age_months' => 180, 'contract_template' => 'Modelo para teste.']);
        BenefitRule::create(['product_version_id' => $v->id, 'name' => 'Consulta', 'code' => 'CONS', 'waiting_days' => 0, 'quantity_limit' => 2, 'period_type' => 'contract_year']);

        return app(CatalogService::class)->publish($v, $this->admin);
    }

    private function proposalBy(User $actor, string $channel, ?AttributionData $attribution = null, ?Pet $pet = null): Proposal
    {
        return app(ProposalService::class)->create(['customer_id' => $this->customer->id, 'pet_id' => ($pet ?? $this->pet)->id, 'product_version_id' => $this->version->id,
            'originating_franchise_id' => $this->a->id, 'channel' => $channel], $actor, $attribution);
    }

    private function signed(Proposal $proposal): Subscription
    {
        return app(ProposalService::class)->accept($proposal, $this->client, ['accepted' => true]);
    }

    private function initial(Subscription $subscription): Invoice
    {
        return $subscription->invoices()->where('is_initial', true)->firstOrFail();
    }

    private function settle(Invoice $invoice, string $status = 'paid', ?int $paid = null, ?string $reference = null): Invoice
    {
        $this->travel(1)->seconds();

        return app(FinancialService::class)->applyVerifiedInvoice($invoice->fresh(), ['status' => $status, 'paid_cents' => $paid ?? $invoice->amount_cents,
            'paid_at' => now()->toIso8601String(), 'provider_updated_at' => now()->toIso8601String(), 'reference' => $reference ?? 'transaction-'.$invoice->id, 'source' => 'test_verified']);
    }

    public function test_staff_proposal_pays_the_staff_member_only_after_the_first_payment_is_confirmed(): void
    {
        $subscription = $this->signed($this->proposalBy($this->seller, 'link'));
        $this->assertSame(0, Commission::count(), 'O aceite sozinho não gera comissão.');
        $invoice = $this->initial($subscription);
        $this->settle($invoice, 'partial', 5000, 'partial-'.$invoice->id);
        $this->assertSame(0, Commission::count(), 'Pagamento parcial não gera comissão.');

        $this->settle($invoice);
        $commission = Commission::sole();
        $this->assertSame('seller', $commission->beneficiary_type);
        $this->assertSame($this->seller->id, $commission->beneficiary_user_id);
        $this->assertSame($this->seller->id, $commission->sold_by_id);
        $this->assertSame('seller_proposal', $commission->rule);
        $this->assertSame(14990, $commission->base_cents);
        $this->assertSame(1000, $commission->rate_basis_points);
        $this->assertSame(1499, $commission->amount_cents);
        $this->assertSame('payable', $commission->status);
        $this->assertSame('link', $commission->origin_snapshot['channel']);
        $this->assertTrue(AuditLog::where('action', 'commission_earned')->where('entity_id', $commission->id)->exists());
    }

    public function test_site_self_service_without_staff_is_recorded_without_automatic_commission(): void
    {
        $this->settle($this->initial($this->signed($this->proposalBy($this->client, 'site'))));
        $commission = Commission::sole();
        $this->assertSame('unassigned', $commission->beneficiary_type);
        $this->assertNull($commission->beneficiary_user_id);
        $this->assertNull($commission->sold_by_id);
        $this->assertSame('Sem responsável comissionado', $commission->beneficiary_name);
        $this->assertSame('site_self_service', $commission->rule);
        $this->assertSame('not_applicable', $commission->status);
        $this->assertSame(0, $commission->amount_cents);
    }

    public function test_customer_who_arrived_through_a_staff_link_pays_the_link_owner(): void
    {
        $attribution = new AttributionData(['acquisition_source' => 'shared_link', 'link_owner_id' => $this->seller->id, 'campaign_name' => 'Outubro']);
        $this->settle($this->initial($this->signed($this->proposalBy($this->client, 'site', $attribution))));
        $commission = Commission::sole();
        $this->assertSame('link_owner', $commission->beneficiary_type);
        $this->assertSame($this->seller->id, $commission->beneficiary_user_id);
        $this->assertNull($commission->sold_by_id);
        $this->assertSame('Outubro', $commission->origin_snapshot['campaign_name']);
    }

    public function test_referral_by_another_tutor_keeps_origin_without_paying_a_customer_automatically(): void
    {
        $attribution = new AttributionData(['acquisition_source' => 'referral', 'referrer_name' => 'Vizinha do tutor']);
        $this->settle($this->initial($this->signed($this->proposalBy($this->client, 'site', $attribution))));
        $commission = Commission::sole();
        $this->assertSame('unassigned', $commission->beneficiary_type);
        $this->assertSame('customer_referral', $commission->rule);
        $this->assertSame('Vizinha do tutor', $commission->origin_snapshot['referrer_name']);
        $this->assertSame('not_applicable', $commission->status);
    }

    public function test_missing_rate_waits_and_is_calculated_when_the_rate_is_defined(): void
    {
        CommissionRate::query()->delete();
        $this->settle($this->initial($this->signed($this->proposalBy($this->seller, 'link'))));
        $commission = Commission::sole();
        $this->assertSame('awaiting_rate', $commission->status);
        $this->assertSame(0, $commission->amount_cents);
        $this->assertNull($commission->rate_basis_points);

        app(CommissionService::class)->setRate($this->version->product, 750, $this->admin, 'Política aprovada');
        $commission->refresh();
        $this->assertSame('payable', $commission->status);
        $this->assertSame(750, $commission->rate_basis_points);
        $this->assertSame(1124, $commission->amount_cents);
    }

    public function test_changing_the_rate_does_not_change_commissions_already_calculated(): void
    {
        $this->settle($this->initial($this->signed($this->proposalBy($this->seller, 'link'))));
        $first = Commission::sole();
        $this->travel(1)->minutes();
        app(CommissionService::class)->setRate($this->version->product, 2000, $this->admin);
        $this->assertSame(1499, $first->fresh()->amount_cents);

        $otherPet = Pet::create(['customer_id' => $this->customer->id, 'name' => 'Bento', 'species' => 'cat', 'birth_date' => now()->subYears(2)->toDateString()]);
        $this->settle($this->initial($this->signed($this->proposalBy($this->seller, 'link', null, $otherPet))));
        $second = Commission::whereKeyNot($first->id)->sole();
        $this->assertSame(2000, $second->rate_basis_points);
        $this->assertSame(2998, $second->amount_cents);
    }

    public function test_fixed_amount_rule_is_snapshotted_on_the_first_confirmed_payment(): void
    {
        app(CommissionService::class)->setRule($this->version->product, 'fixed', 2500, $this->admin, 'Valor fixo aprovado');
        $this->settle($this->initial($this->signed($this->proposalBy($this->seller, 'link'))));

        $commission = Commission::sole();
        $this->assertSame('fixed', $commission->calculation_type);
        $this->assertSame(2500, $commission->fixed_amount_cents);
        $this->assertSame(2500, $commission->amount_cents);
        $this->assertSame('payable', $commission->status);
    }

    public function test_refund_reverses_the_commission_and_flags_clawback_when_already_paid_out(): void
    {
        $invoice = $this->initial($this->signed($this->proposalBy($this->seller, 'link')));
        $this->settle($invoice);
        app(CommissionService::class)->markPaid(Commission::sole(), $this->finance, 'PIX 2026-10');
        $this->settle($invoice, 'refunded', 0);

        $commission = Commission::sole();
        $this->assertSame('reversed', $commission->status);
        $this->assertTrue($commission->clawback);
        $this->assertSame('Primeiro pagamento estornado.', $commission->reversal_reason);
    }

    public function test_cancelled_first_invoice_reverses_an_unpaid_commission_without_clawback(): void
    {
        $invoice = $this->initial($this->signed($this->proposalBy($this->seller, 'link')));
        $this->settle($invoice);
        $this->settle($invoice, 'cancelled', 0);
        $commission = Commission::sole();
        $this->assertSame('reversed', $commission->status);
        $this->assertFalse($commission->clawback);
    }

    public function test_dispute_holds_the_commission_and_a_confirmed_payment_releases_it(): void
    {
        $invoice = $this->initial($this->signed($this->proposalBy($this->seller, 'link')));
        $this->settle($invoice);
        $this->settle($invoice, 'disputed');
        $this->assertSame('held', Commission::sole()->status);
        $this->expectException(DomainException::class);
        try {
            app(CommissionService::class)->markPaid(Commission::sole(), $this->finance, 'PIX 2026-10');
        } finally {
            $this->settle($invoice);
            $this->assertSame('payable', Commission::sole()->status);
        }
    }

    public function test_repeated_confirmations_and_the_sync_command_never_duplicate(): void
    {
        $invoice = $this->initial($this->signed($this->proposalBy($this->seller, 'link')));
        $this->settle($invoice);
        $this->settle($invoice);
        $this->artisan('commissions:sync')->assertSuccessful();
        $this->assertSame(1, Commission::count());
    }

    public function test_commission_page_respects_roles_and_scope(): void
    {
        $this->settle($this->initial($this->signed($this->proposalBy($this->seller, 'link'))));
        $otherPet = Pet::create(['customer_id' => $this->customer->id, 'name' => 'Bento', 'species' => 'cat', 'birth_date' => now()->subYears(2)->toDateString()]);
        $this->settle($this->initial($this->signed($this->proposalBy($this->client, 'site', null, $otherPet))));
        $sellerCommission = Commission::where('beneficiary_type', 'seller')->sole();
        $unassignedCommission = Commission::where('beneficiary_type', 'unassigned')->sole();

        $this->actingAs($this->seller)->get(route('commissions.index'))->assertOk()
            ->assertSee('Minhas comissões')->assertSee('Equipe que fez a proposta')->assertDontSee('Contratação direta pelo site');
        $this->actingAs($this->seller)->post(route('commissions.pay', $sellerCommission), ['payout_reference' => 'PIX 1'])->assertForbidden();
        $this->actingAs($this->staff('Recepção A', 'operator', $this->a))->get(route('commissions.index'))->assertForbidden();
        $this->actingAs($this->client)->get(route('commissions.index'))->assertForbidden();

        $outsider = $this->staff('Financeiro B', 'finance', $this->b);
        $this->actingAs($outsider)->get(route('commissions.index'))->assertOk()->assertDontSee('Tutor comissão');
        $this->actingAs($outsider)->post(route('commissions.pay', $sellerCommission), ['payout_reference' => 'PIX 2'])->assertForbidden();

        $this->actingAs($this->finance)->post(route('commissions.pay', $unassignedCommission), ['payout_reference' => 'PIX 2'])->assertRedirect();
        $this->assertSame('not_applicable', $unassignedCommission->fresh()->status);
        $this->actingAs($this->admin)->post(route('commissions.pay', $sellerCommission), ['payout_reference' => 'Folha outubro'])->assertRedirect();
        $this->assertSame('paid', $sellerCommission->fresh()->status);
        $this->assertSame($this->admin->id, $sellerCommission->fresh()->paid_by);

        $this->actingAs($this->staff('Gestão A', 'manager', $this->a))->post(route('commissions.rates'), ['rates' => [$this->version->product_id => 12]])->assertForbidden();
        $this->actingAs($this->admin)->post(route('commissions.rates'), ['rates' => [$this->version->product_id => '12.5']])->assertRedirect();
        $this->assertSame(1250, CommissionRate::where('product_id', $this->version->product_id)->latest('id')->value('rate_basis_points'));
    }
}

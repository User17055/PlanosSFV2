<?php

namespace Tests\Feature;

use App\Models\BenefitRule;
use App\Models\ContractDocument;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Pet;
use App\Models\PetProductSlot;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\Proposal;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Domain\ActivationService;
use App\Services\Domain\BenefitService;
use App\Services\Domain\CancellationService;
use App\Services\Domain\CatalogService;
use App\Services\Domain\DomainException;
use App\Services\Domain\EligibilityService;
use App\Services\Domain\FinancialService;
use App\Services\Domain\PeriodCalculator;
use App\Services\Domain\ProposalService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DomainRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Franchise $a;

    private Franchise $b;

    private Customer $customer;

    private Pet $pet;

    private User $client;

    private ProductVersion $version;

    private BenefitRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        config(['plans.demo' => true, 'plans.financial_max_age_minutes' => 1440, 'plans.reservation_minutes' => 60]);
        Storage::fake('local');
        $this->a = Franchise::create(['name' => 'Unidade A', 'code' => 'A']);
        $this->b = Franchise::create(['name' => 'Unidade B', 'code' => 'B']);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'Example123!', 'role' => 'central_admin', 'active' => true]);
        $this->customer = Customer::create(['name' => 'Tutor fictício', 'document' => 'DEMO-TEST', 'email' => 'customer@example.test', 'contact_verified_at' => now(),
            'financial_verification_status' => 'verified', 'financial_checked_at' => now()]);
        $this->pet = Pet::create(['customer_id' => $this->customer->id, 'name' => 'Luna', 'species' => 'dog', 'birth_date' => now()->subYears(4)->toDateString()]);
        $this->client = User::create(['name' => 'Tutor', 'email' => 'client@example.test', 'password' => 'Example123!', 'role' => 'customer', 'customer_id' => $this->customer->id, 'active' => true]);
        $this->version = $this->makeVersion();
        $this->rule = $this->version->benefitRules()->firstOrFail();
    }

    private function makeVersion(string $category = 'health_plan'): ProductVersion
    {
        $p = Product::create(['name' => 'Produto teste', 'code' => Str::uuid(), 'category' => $category]);
        $v = ProductVersion::create(['product_id' => $p->id, 'version' => 1, 'name' => 'Versão teste', 'price_cents' => 10000,
            'billing_cycle' => $category === 'package' ? 'one_time' : 'monthly', 'duration_months' => $category === 'package' ? 6 : null,
            'renewal_policy' => 'none', 'species' => ['dog', 'cat'], 'min_age_months' => 2, 'max_age_months' => 180, 'contract_template' => 'Modelo para teste.']);
        BenefitRule::create(['product_version_id' => $v->id, 'name' => 'Consulta', 'code' => 'CONS', 'waiting_days' => 30, 'quantity_limit' => 2, 'period_type' => 'contract_year']);

        return app(CatalogService::class)->publish($v, $this->admin);
    }

    private function proposal(?ProductVersion $version = null, ?Pet $pet = null): Proposal
    {
        return app(ProposalService::class)->create(['customer_id' => $this->customer->id, 'pet_id' => ($pet ?? $this->pet)->id,
            'product_version_id' => ($version ?? $this->version)->id, 'originating_franchise_id' => $this->a->id, 'channel' => 'site'], $this->client);
    }

    private function signed(?ProductVersion $version = null, ?Pet $pet = null): Subscription
    {
        return app(ProposalService::class)->accept($this->proposal($version, $pet), $this->client, ['accepted' => true]);
    }

    private function document(Subscription $s): ContractDocument
    {
        $path = 'contracts/test-'.$s->id.'.pdf';
        $bytes = '%PDF-1.4 test-domain %%EOF';
        Storage::disk('local')->put($path, $bytes);
        $d = ContractDocument::create(['subscription_id' => $s->id, 'proposal_id' => $s->proposal_id, 'kind' => 'final_contract', 'signature_method' => 'digital',
            'status' => 'valid', 'storage_path' => $path, 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes), 'version' => 1, 'accepted_at' => now(), 'validated_at' => now()]);
        $s->update(['document_status' => 'valid']);

        return $d;
    }

    private function pay(Invoice $invoice, ?string $reference = null): Invoice
    {
        return app(FinancialService::class)->applyVerifiedInvoice($invoice, ['status' => 'paid', 'paid_cents' => $invoice->amount_cents,
            'paid_at' => now()->toIso8601String(), 'provider_updated_at' => now()->toIso8601String(), 'reference' => $reference ?? 'transaction-'.$invoice->id, 'source' => 'test_verified']);
    }

    private function active(int $ageDays = 60, ?ProductVersion $version = null, ?Pet $pet = null): Subscription
    {
        $this->travelTo(now()->subDays($ageDays));
        try {
            $s = $this->signed($version, $pet);
            $this->document($s);
            $this->pay($s->invoices()->firstOrFail());
        } finally {
            $this->travelBack();
        }
        $this->customer->refresh()->update(['financial_checked_at' => now()]);
        $s->refresh()->update(['financial_checked_at' => now()]);

        return $s->fresh();
    }

    private function invoice(Subscription $s, int $daysDue = 1, int $paid = 0): Invoice
    {
        return Invoice::create(['subscription_id' => $s->id, 'customer_id' => $s->customer_id, 'originating_franchise_id' => $s->originating_franchise_id,
            'due_at' => now()->addDays($daysDue), 'amount_cents' => 10000, 'paid_cents' => $paid, 'status' => $paid > 0 ? 'partial' : 'pending', 'reconciliation_status' => 'verified']);
    }

    public function test_payment_without_document_does_not_activate_and_reprocessing_preserves_clock(): void
    {
        $s = $this->signed();
        $this->pay($s->invoices()->firstOrFail());
        $this->assertSame('awaiting_regularization', $s->fresh()->status);
        $this->assertContains('CONTRACT_PENDING', array_column(app(EligibilityService::class)->subscription($s->fresh())['impediments'], 'code'));
        $this->document($s);
        app(ActivationService::class)->activate($s->fresh());
        $at = $s->fresh()->activated_at;
        $this->travel(2)->hours();
        app(ActivationService::class)->activate($s->fresh());
        $this->assertTrue($at->equalTo($s->fresh()->activated_at));
        $this->assertSame('active', $s->fresh()->status);
    }

    public function test_valid_document_without_first_full_payment_does_not_activate(): void
    {
        $s = $this->signed();
        $this->document($s);
        $invoice = $s->invoices()->firstOrFail();
        app(FinancialService::class)->applyVerifiedInvoice($invoice, ['status' => 'partial', 'paid_cents' => 5000, 'reference' => 'partial-1', 'source' => 'test', 'provider_updated_at' => now()]);
        $this->assertFalse(app(ActivationService::class)->activate($s->fresh())['eligible']);
        $this->assertNull($s->fresh()->activated_at);
    }

    public function test_missing_private_file_blocks_activation_even_with_valid_metadata(): void
    {
        $s = $this->signed();
        $d = $this->document($s);
        Storage::disk('local')->delete($d->storage_path);
        $this->pay($s->invoices()->firstOrFail());
        $this->assertNull($s->fresh()->activated_at);
    }

    public function test_acceptance_is_idempotent_and_category_slot_exclusive_but_package_allowed(): void
    {
        $p = $this->proposal();
        $s = app(ProposalService::class)->accept($p, $this->client, ['accepted' => true]);
        $same = app(ProposalService::class)->accept($p->fresh(), $this->client, ['accepted' => true]);
        $this->assertSame($s->id, $same->id);
        $this->assertSame(1, Invoice::count());
        $this->signed($this->makeVersion('package'));
        $this->assertSame(2, PetProductSlot::where('state', 'reserved')->count());
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('já possui contratação');
        $this->signed();
    }

    public function test_seller_cannot_accept_for_customer_using_a_boolean(): void
    {
        $this->expectException(DomainException::class);
        app(ProposalService::class)->accept($this->proposal(), $this->admin, ['accepted' => true, 'customer_acceptance' => true]);
    }

    public function test_direct_sql_cannot_unfreeze_or_change_accepted_contract_even_with_status_change(): void
    {
        $s = $this->signed();
        $this->expectException(QueryException::class);
        DB::table('subscriptions')->where('id', $s->id)->update(['locked_at' => null, 'status' => 'cancelled', 'price_cents' => 1]);
    }

    public function test_published_coverage_is_immutable_even_through_mass_update(): void
    {
        $this->expectException(QueryException::class);
        DB::table('benefit_rules')->where('id', $this->rule->id)->update(['waiting_days' => 0]);
    }

    public function test_debt_in_another_pet_and_franchise_blocks_all_benefits_without_scheduler(): void
    {
        $s = $this->active();
        $second = Pet::create(['customer_id' => $this->customer->id, 'name' => 'Outro pet', 'species' => 'cat', 'birth_date' => now()->subYears(3)->toDateString()]);
        $other = $this->signed($this->version, $second);
        $debt = $this->invoice($other, -1);
        $debt->update(['originating_franchise_id' => $this->b->id]);
        $decision = app(EligibilityService::class)->benefit($s, $this->rule, $this->a->id);
        $this->assertFalse($decision['eligible']);
        $this->assertSame('CUSTOMER_OVERDUE', $decision['code']);
        $this->assertSame(10000, app(FinancialService::class)->customerStatus($this->customer->fresh())['overdue_cents']);
    }

    public function test_future_invoice_does_not_block_and_all_overdue_debts_must_be_settled(): void
    {
        $s = $this->active();
        $this->invoice($s, 3);
        $this->assertTrue(app(EligibilityService::class)->benefit($s, $this->rule, $this->a->id)['eligible']);
        $debt1 = $this->invoice($s, -1);
        $debt2 = $this->invoice($s, -2);
        $this->pay($debt1);
        $this->assertTrue(app(FinancialService::class)->customerStatus($this->customer->fresh())['blocked']);
        $this->pay($debt2);
        $this->assertFalse(app(FinancialService::class)->customerStatus($this->customer->fresh())['blocked']);
        $this->assertSame('active', $s->fresh()->status);
    }

    public function test_waiting_period_returns_release_date_and_does_not_reset_on_monthly_payment(): void
    {
        $s = $this->active(5);
        $before = $s->effective_start_at;
        $decision = app(EligibilityService::class)->benefit($s, $this->rule, $this->a->id);
        $this->assertSame('BENEFIT_WAITING_PERIOD', $decision['code']);
        $this->assertNotNull($decision['available_from']);
        $this->pay($this->invoice($s));
        $this->assertTrue($before->equalTo($s->fresh()->effective_start_at));
    }

    public function test_atomic_reservations_count_against_limit_and_cancel_releases_only_reservation(): void
    {
        $s = $this->active();
        $service = app(BenefitService::class);
        $one = $service->reserve($s, $this->rule, $this->admin, $this->a->id, 1, 'one', 'item-one');
        $two = $service->reserve($s, $this->rule, $this->admin, $this->b->id, 1, 'two', 'item-two');
        $this->assertSame(0, app(EligibilityService::class)->benefit($s, $this->rule, $this->a->id)['remaining']);
        try {
            $service->reserve($s, $this->rule, $this->admin, $this->a->id, 1, 'three', 'item-three');
            $this->fail('Exceeded limit');
        } catch (DomainException $e) {
            $this->assertSame('BENEFIT_LIMIT_REACHED', $e->errorCode);
        }
        $service->consume($one, $this->admin);
        $service->cancelReservation($two, $this->admin, 'Agendamento cancelado');
        $this->assertSame(1, app(EligibilityService::class)->benefit($s, $this->rule, $this->a->id)['remaining']);
    }

    public function test_debt_after_reservation_prevents_execution(): void
    {
        $s = $this->active();
        $u = app(BenefitService::class)->reserve($s, $this->rule, $this->admin, $this->a->id, 1, 'reserve');
        $this->invoice($s, -1);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('mensalidade vencida');
        app(BenefitService::class)->consume($u, $this->admin);
    }

    public function test_idempotency_conflicts_and_same_service_cannot_use_both_categories(): void
    {
        $s = $this->active();
        $service = app(BenefitService::class);
        $u = $service->reserve($s, $this->rule, $this->admin, $this->a->id, 1, 'same', 'service-same');
        $again = $service->reserve($s, $this->rule, $this->admin, $this->a->id, 1, 'same', 'service-same');
        $this->assertSame($u->id, $again->id);
        try {
            $service->reserve($s, $this->rule, $this->admin, $this->a->id, 2, 'same', 'service-same');
            $this->fail('Changed idempotency data');
        } catch (DomainException $e) {
            $this->assertSame('IDEMPOTENCY_CONFLICT', $e->errorCode);
        }
        $v = $this->makeVersion('package');
        $package = $this->active(60, $v);
        $this->expectException(DomainException::class);
        $service->reserve($package, $v->benefitRules()->firstOrFail(), $this->admin, $this->a->id, 1, 'other', 'service-same');
    }

    public function test_reversal_preserves_consumption_and_cannot_exceed_original(): void
    {
        $s = $this->active();
        $service = app(BenefitService::class);
        $u = $service->reserve($s, $this->rule, $this->admin, $this->a->id, 1, 'consume');
        $service->consume($u, $this->admin);
        $r = $service->reverse($u, $this->admin, 1, 'Correção de lançamento');
        $this->assertSame('consumed', $u->fresh()->status);
        $this->assertSame($u->id, $r->original_usage_id);
        $this->assertSame(2, app(EligibilityService::class)->benefit($s, $this->rule, $this->a->id)['remaining']);
        $this->expectException(DomainException::class);
        $service->reverse($u, $this->admin, 1, 'Estorno duplicado');
    }

    public function test_cancelled_contract_cannot_reactivate_after_late_payment_and_new_contract_has_new_waiting_period(): void
    {
        $s = $this->active();
        $old = $s->effective_start_at;
        $future = $this->invoice($s, 2);
        app(CancellationService::class)->cancel($s, $this->admin, 'Nova contratação solicitada');
        $this->pay($future);
        $this->assertSame('cancelled', $s->fresh()->status);
        $new = $this->active(0);
        $this->assertNotEquals($s->id, $new->id);
        $this->assertTrue($new->effective_start_at->gt($old));
        $this->assertSame('BENEFIT_WAITING_PERIOD', app(EligibilityService::class)->benefit($new, $this->rule, $this->a->id)['code']);
    }

    public function test_scheduled_cancellation_keeps_slot_until_effective_end_then_scheduler_releases(): void
    {
        $s = $this->active();
        app(CancellationService::class)->cancel($s, $this->admin, 'Encerrar ao fim do período', now()->addDay()->toIso8601String());
        $this->assertSame('occupied', $s->slot()->first()->state);
        $this->travel(2)->days();
        $this->assertSame(1, app(CancellationService::class)->finalizeDue());
        $this->assertSame('released', $s->slot()->first()->state);
        $this->assertSame('ended', $s->fresh()->status);
    }

    public function test_financial_freshness_fails_closed_and_revoked_franchise_cannot_reserve(): void
    {
        $s = $this->active();
        $this->customer->update(['financial_checked_at' => now()->subDays(2)]);
        $this->assertSame('FINANCIAL_VERIFICATION_PENDING', app(EligibilityService::class)->benefit($s->fresh(), $this->rule, $this->a->id)['code']);
        $operator = User::create(['name' => 'Reception', 'email' => 'operator@example.test', 'password' => 'Example123!', 'role' => 'operator', 'franchise_id' => $this->a->id, 'active' => true]);
        $operator->franchises()->attach($this->a->id, ['active' => false]);
        $this->assertFalse($operator->hasFranchise($this->a->id));
        $this->expectException(DomainException::class);
        app(BenefitService::class)->reserve($s, $this->rule, $operator, $this->a->id, 1, 'forbidden');
    }

    public function test_monthly_anniversary_preserves_original_day_and_annual_leap_anchor(): void
    {
        $calculator = new PeriodCalculator;
        $s = new Subscription(['effective_start_at' => '2024-01-31 13:00:00']);
        $r = new BenefitRule(['period_type' => 'monthly_cycle']);
        [$start,$end] = $calculator->calculate($s, $r, CarbonImmutable::parse('2024-02-29 13:00:00', 'UTC'));
        $this->assertSame('2024-02-29 13:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2024-03-31 13:00:00', $end->format('Y-m-d H:i:s'));
        $s = new Subscription(['effective_start_at' => '2024-02-29 13:00:00']);
        $r = new BenefitRule(['period_type' => 'contract_year']);
        [$start,$end] = $calculator->calculate($s, $r, CarbonImmutable::parse('2025-02-28 13:00:00', 'UTC'));
        $this->assertSame('2025-02-28 13:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-28 13:00:00', $end->format('Y-m-d H:i:s'));
    }

    public function test_paying_one_contract_cannot_hide_another_stale_or_uncertain_contract(): void
    {
        $health = $this->active();
        $package = $this->active(40, $this->makeVersion('package'));
        $health->update(['financial_checked_at' => now()->subDays(3)]);
        $this->pay($this->invoice($package));
        $this->assertSame('pending', app(FinancialService::class)->customerStatus($this->customer->fresh())['status']);
        $health->refresh()->update(['financial_checked_at' => now()]);
        $uncertain = $this->invoice($health, 2);
        $uncertain->update(['reconciliation_status' => 'uncertain']);
        $this->pay($this->invoice($package));
        $this->assertSame('pending', app(FinancialService::class)->customerStatus($this->customer->fresh())['status']);
    }

    public function test_refund_or_dispute_requires_global_resolution_even_when_paid_amount_is_full(): void
    {
        $health = $this->active();
        $package = $this->active(40, $this->makeVersion('package'));
        $invoice = $this->invoice($health);
        $this->pay($invoice);
        $this->travel(1)->seconds();
        app(FinancialService::class)->applyVerifiedInvoice($invoice->fresh(), ['status' => 'disputed', 'paid_cents' => 10000, 'reference' => 'transaction-'.$invoice->id, 'provider_updated_at' => now(), 'source' => 'test_verified']);
        $this->assertSame('pending', app(FinancialService::class)->customerStatus($this->customer->fresh())['status']);
        $this->assertFalse(app(EligibilityService::class)->subscription($package->fresh())['eligible']);
        $this->travel(1)->seconds();
        app(FinancialService::class)->applyVerifiedInvoice($invoice->fresh(), ['status' => 'refunded', 'paid_cents' => 0, 'provider_updated_at' => now(), 'source' => 'test_verified']);
        $this->assertSame('pending', app(FinancialService::class)->customerStatus($this->customer->fresh())['status']);
        $this->assertSame(2, Payment::whereIn('invoice_id', $health->invoices()->pluck('id'))->count());
    }

    public function test_stale_financial_event_does_not_regress_paid_invoice_or_duplicate_payment(): void
    {
        $s = $this->active();
        $invoice = $s->invoices()->firstOrFail();
        $clock = $s->activated_at;
        app(FinancialService::class)->applyVerifiedInvoice($invoice, ['status' => 'pending', 'paid_cents' => 0, 'provider_updated_at' => $invoice->provider_updated_at->copy()->subDay(), 'source' => 'stale_test']);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, $invoice->payments()->count());
        $this->assertTrue($clock->equalTo($s->fresh()->activated_at));
    }

    public function test_latest_rejected_document_overrides_an_older_valid_document(): void
    {
        $s = $this->active();
        $old = $s->documents()->firstOrFail();
        $attributes = $old->getAttributes();
        unset($attributes['id'],$attributes['created_at'],$attributes['updated_at']);
        $attributes['version'] = 2;
        $attributes['status'] = 'rejected';
        $attributes['rejection_reason'] = 'Digitalização incompleta';
        ContractDocument::create($attributes);
        $decision = app(EligibilityService::class)->subscription($s->fresh());
        $this->assertContains('CONTRACT_PENDING', array_column($decision['impediments'], 'code'));
        app(ActivationService::class)->activate($s->fresh());
        $this->assertSame('suspended', $s->fresh()->status);
    }

    public function test_customer_digital_endpoint_cannot_replace_tablet_or_paper_signature(): void
    {
        foreach (['tablet', 'paper'] as $method) {
            $p = app(ProposalService::class)->create(['customer_id' => $this->customer->id, 'pet_id' => $this->pet->id,
                'product_version_id' => $this->version->id, 'originating_franchise_id' => $this->a->id, 'channel' => 'presential', 'signature_method' => $method], $this->admin);
            try {
                app(ProposalService::class)->accept($p, $this->client, ['accepted' => true]);
                $this->fail('Signature channel bypass');
            } catch (DomainException $e) {
                $this->assertSame('CUSTOMER_ACCEPTANCE_REQUIRED', $e->errorCode);
            }
        }
        $this->assertSame(0, Subscription::count());
    }

    public function test_changed_identity_requires_fresh_review_and_stale_browser_is_rejected(): void
    {
        $service = app(ProposalService::class);
        $p = $this->proposal();
        $original = $service->reviewFingerprint($p);
        $this->customer->update(['name' => 'Nome corrigido pelo titular']);
        try {
            $service->accept($p, $this->client, ['accepted' => true, 'review_fingerprint' => $original]);
            $this->fail('Accepted changed identity');
        } catch (DomainException $e) {
            $this->assertSame('PROPOSAL_REVIEW_REQUIRED', $e->errorCode);
        }
        $p = $service->refreshForReview($p);
        try {
            $service->accept($p, $this->client, ['accepted' => true, 'review_fingerprint' => $original]);
            $this->fail('Accepted stale browser');
        } catch (DomainException $e) {
            $this->assertSame('PROPOSAL_REVIEW_REQUIRED', $e->errorCode);
        }
        $s = $service->accept($p, $this->client, ['accepted' => true, 'review_fingerprint' => $service->reviewFingerprint($p)]);
        $this->assertSame('Nome corrigido pelo titular', data_get($s->contract_snapshot, 'customer.name'));
    }

    public function test_reserved_quantity_expires_and_cannot_be_consumed_after_expiration(): void
    {
        $s = $this->active();
        $service = app(BenefitService::class);
        $u = $service->reserve($s, $this->rule, $this->admin, $this->a->id, 2, 'expiring');
        $this->travel(61)->minutes();
        $this->assertSame(2, app(EligibilityService::class)->benefit($s->fresh(), $this->rule, $this->a->id)['remaining']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Reserva expirada');
        $service->consume($u, $this->admin);
    }

    public function test_reversal_retry_is_idempotent_and_changed_payload_conflicts(): void
    {
        $s = $this->active();
        $service = app(BenefitService::class);
        $u = $service->reserve($s, $this->rule, $this->admin, $this->a->id, 2, 'two-units');
        $service->consume($u, $this->admin);
        $one = $service->reverse($u, $this->admin, 1, 'Correção autorizada', 'reverse-unique');
        $same = $service->reverse($u, $this->admin, 1, 'Correção autorizada', 'reverse-unique');
        $this->assertSame($one->id, $same->id);
        $this->assertSame(1, $u->reversals()->count());
        try {
            $service->reverse($u, $this->admin, 2, 'Correção autorizada', 'reverse-unique');
            $this->fail('Accepted changed reversal');
        } catch (DomainException $e) {
            $this->assertSame('IDEMPOTENCY_CONFLICT', $e->errorCode);
        }
    }

    public function test_future_start_is_frozen_in_database_after_acceptance(): void
    {
        $s = $this->signed();
        $this->expectException(QueryException::class);
        DB::table('subscriptions')->where('id', $s->id)->update(['requested_start_at' => now()->addMonth()]);
    }

    public function test_existing_alternate_product_version_is_rejected_by_contract_guard_not_foreign_key(): void
    {
        $s = $this->signed();
        $alternate = $this->makeVersion();
        foreach (['subscriptions' => $s->id, 'proposals' => $s->proposal_id] as $table => $id) {
            try {
                DB::table($table)->where('id', $id)->update(['product_version_id' => $alternate->id]);
                $this->fail('A valid referenced product version bypassed the contractual guard on '.$table);
            } catch (QueryException $e) {
                $this->assertStringContainsString('CONTRACT_IMMUTABLE', $e->getMessage());
            }
            $this->assertSame($this->version->id, (int) DB::table($table)->where('id', $id)->value('product_version_id'));
        }
    }

    public function test_database_preserves_terminal_status_and_activation_clock_with_specific_guard_errors(): void
    {
        $s = $this->active();
        try {
            DB::table('subscriptions')->where('id', $s->id)->update(['effective_start_at' => now()->subYear()]);
            $this->fail('Activation clock became editable.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ACTIVATION_CLOCK_IMMUTABLE', $e->getMessage());
        }
        app(CancellationService::class)->cancel($s, $this->admin, 'Encerramento de teste');
        try {
            DB::table('subscriptions')->where('id', $s->id)->update(['status' => 'active']);
            $this->fail('Terminal subscription became active.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('TERMINAL_SUBSCRIPTION', $e->getMessage());
        }
    }

    public function test_history_delete_guards_raise_their_own_error_instead_of_only_foreign_key_failures(): void
    {
        $s = $this->signed();
        foreach (['subscriptions' => $s->id, 'proposals' => $s->proposal_id] as $table => $id) {
            try {
                DB::table($table)->where('id', $id)->delete();
                $this->fail('Committed history was deleted from '.$table);
            } catch (QueryException $e) {
                $this->assertStringContainsString('HISTORY_PRESERVED', $e->getMessage());
            }
        }
    }

    public function test_sqlite_guard_inventory_and_repair_preserve_all_contractual_protections(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite rebuild regression; MySQL behavior is covered by the SQL guard tests.');
        }
        $inventory = fn () => DB::table('sqlite_master')->where('type', 'trigger')->orderBy('name')->pluck('name')->all();
        $before = $inventory();
        $expected = [
            'subscriptions_immutable_update', 'proposals_immutable_update', 'products_immutable_update', 'product_versions_immutable_update', 'contract_documents_immutable_update',
            'benefit_rules_published_insert', 'benefit_rules_published_update', 'benefit_rules_published_delete',
            'subscriptions_preserve_delete', 'proposals_preserve_delete', 'contract_documents_preserve_delete', 'product_versions_preserve_delete', 'benefit_usages_preserve_delete', 'audit_logs_preserve_delete',
            'subscriptions_terminal', 'subscriptions_activation_clock', 'audit_logs_append_only', 'benefit_usages_append_only',
            'proposals_attribution_immutable', 'subscriptions_attribution_immutable', 'attribution_touches_append_only_update', 'attribution_touches_append_only_delete', 'marketing_links_identity_immutable',
        ];
        foreach ($expected as $name) {
            $this->assertContains($name, $before, 'Missing protection after all migrations: '.$name);
        }
        // Reproduce the exact missing-trigger inventory caused by SQLite rebuilding the two tables.
        $lost = ['subscriptions_immutable_update', 'proposals_immutable_update', 'subscriptions_preserve_delete', 'proposals_preserve_delete', 'subscriptions_terminal', 'subscriptions_activation_clock'];
        foreach ($lost as $name) {
            DB::unprepared('DROP TRIGGER '.$name);
        }
        $missing = $inventory();
        foreach ($lost as $name) {
            $this->assertNotContains($name, $missing);
        }
        $this->assertContains('subscriptions_attribution_immutable', $missing);
        $repair = require database_path('migrations/2026_10_19_000005_restore_contract_guards_after_attribution.php');
        $repair->up();
        $this->assertSame($before, $inventory());
        $repair->up();
        $this->assertSame($before, $inventory(), 'Repair must be idempotent and preserve unrelated triggers.');
    }

    public function test_monthly_invoice_does_not_restore_contract_year_balance(): void
    {
        $s = $this->active();
        $service = app(BenefitService::class);
        $u = $service->reserve($s, $this->rule, $this->admin, $this->a->id, 2, 'annual-all');
        $service->consume($u, $this->admin);
        $this->pay($this->invoice($s));
        $this->assertSame(0, app(EligibilityService::class)->benefit($s->fresh(), $this->rule, $this->a->id)['remaining']);
    }
}

<?php

namespace Tests\Concerns;

use App\Models\BenefitRule;
use App\Models\ContractDocument;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Pet;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Domain\CatalogService;
use App\Services\Domain\FinancialService;
use App\Services\Domain\ProposalService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Same scenario as DomainRulesTest: one unit, one tutor with a pet and a published plan with a limited benefit. */
trait BuildsActiveSubscription
{
    protected Franchise $unit;

    protected User $admin;

    protected Customer $customer;

    protected Pet $pet;

    protected User $client;

    protected ProductVersion $version;

    protected BenefitRule $rule;

    protected function setUpScenario(int $quantityLimit = 2): void
    {
        config(['plans.demo' => true, 'plans.financial_max_age_minutes' => 1440, 'plans.reservation_minutes' => 60]);
        Storage::fake('local');
        $this->unit = Franchise::create(['name' => 'Unidade A', 'code' => 'A']);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'Example123!', 'role' => 'central_admin', 'active' => true]);
        $this->customer = Customer::create(['name' => 'Tutor fictício', 'document' => 'DEMO-TEST', 'email' => 'customer@example.test', 'contact_verified_at' => now(),
            'financial_verification_status' => 'verified', 'financial_checked_at' => now()]);
        $this->pet = Pet::create(['customer_id' => $this->customer->id, 'name' => 'Luna', 'species' => 'dog', 'birth_date' => now()->subYears(4)->toDateString()]);
        $this->client = User::create(['name' => 'Tutor', 'email' => 'client@example.test', 'password' => 'Example123!', 'role' => 'customer', 'customer_id' => $this->customer->id, 'active' => true]);
        $product = Product::create(['name' => 'Produto teste', 'code' => Str::uuid(), 'category' => 'health_plan']);
        $version = ProductVersion::create(['product_id' => $product->id, 'version' => 1, 'name' => 'Versão teste', 'price_cents' => 10000, 'billing_cycle' => 'monthly',
            'renewal_policy' => 'none', 'species' => ['dog', 'cat'], 'min_age_months' => 2, 'max_age_months' => 180, 'contract_template' => 'Modelo para teste.']);
        BenefitRule::create(['product_version_id' => $version->id, 'name' => 'Consulta', 'code' => 'CONS', 'waiting_days' => 30, 'quantity_limit' => $quantityLimit, 'period_type' => 'contract_year']);
        $this->version = app(CatalogService::class)->publish($version, $this->admin);
        $this->rule = $this->version->benefitRules()->firstOrFail();
    }

    /** Signed 60 days ago, with a valid document and the first invoice paid, so the waiting period is over. */
    protected function activeSubscription(): Subscription
    {
        $this->travelTo(now()->subDays(60));
        try {
            $proposal = app(ProposalService::class)->create(['customer_id' => $this->customer->id, 'pet_id' => $this->pet->id,
                'product_version_id' => $this->version->id, 'originating_franchise_id' => $this->unit->id, 'channel' => 'site'], $this->client);
            $s = app(ProposalService::class)->accept($proposal, $this->client, ['accepted' => true]);
            $path = 'contracts/test-'.$s->id.'.pdf';
            $bytes = '%PDF-1.4 test-domain %%EOF';
            Storage::disk('local')->put($path, $bytes);
            ContractDocument::create(['subscription_id' => $s->id, 'proposal_id' => $s->proposal_id, 'kind' => 'final_contract', 'signature_method' => 'digital',
                'status' => 'valid', 'storage_path' => $path, 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes), 'version' => 1, 'accepted_at' => now(), 'validated_at' => now()]);
            $s->update(['document_status' => 'valid']);
            $invoice = $s->invoices()->firstOrFail();
            app(FinancialService::class)->applyVerifiedInvoice($invoice, ['status' => 'paid', 'paid_cents' => $invoice->amount_cents,
                'paid_at' => now()->toIso8601String(), 'provider_updated_at' => now()->toIso8601String(), 'reference' => 'transaction-'.$invoice->id, 'source' => 'test_verified']);
        } finally {
            $this->travelBack();
        }
        $this->customer->refresh()->update(['financial_checked_at' => now()]);
        $s->refresh()->update(['financial_checked_at' => now()]);

        return $s->fresh();
    }
}

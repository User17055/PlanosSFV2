<?php

namespace Tests\Feature;

use App\Models\{Customer, Franchise, Lead, Pet, Product, Proposal, Subscription, User};
use App\Services\Domain\{ActivationService, LeadConversion};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_key_ignores_country_code_and_mobile_nine(): void
    {
        $this->assertSame('1896123456', LeadConversion::phoneKey('5518996123456'));
        $this->assertSame('1896123456', LeadConversion::phoneKey('(18) 99612-3456'));
        $this->assertSame('1896123456', LeadConversion::phoneKey('18 9612-3456'));
        $this->assertNull(LeadConversion::phoneKey('123'));
    }

    public function test_first_activation_marks_the_matching_lead_as_finished_with_the_customer_name(): void
    {
        $unit = Franchise::create(['name' => 'Unidade', 'code' => 'CENTRAL', 'active' => true]);
        $customer = Customer::create(['name' => 'Maria Fictícia Souza', 'document' => 'LEAD-CONV', 'email' => 'maria@example.test', 'phone' => '(18) 99612-3456', 'contact_verified_at' => now()]);
        $user = User::factory()->create(['role' => 'customer', 'customer_id' => $customer->id, 'active' => true]);
        $pet = Pet::create(['customer_id' => $customer->id, 'name' => 'Mel', 'species' => 'cat']);
        $product = Product::create(['name' => 'Plano', 'code' => 'LC', 'category' => 'health_plan', 'active' => true]);
        $version = $product->versions()->create(['version' => 1, 'name' => 'Plano', 'price_cents' => 2400, 'enrollment_fee_cents' => 0, 'billing_cycle' => 'monthly',
            'duration_months' => 12, 'renewal_policy' => 'automatic', 'species' => ['cat'], 'contract_template' => 'Teste', 'status' => 'published', 'is_demo' => false, 'published_at' => now()]);
        $proposal = Proposal::create(['number' => 'PR-LC', 'customer_id' => $customer->id, 'pet_id' => $pet->id, 'product_version_id' => $version->id, 'originating_franchise_id' => $unit->id,
            'created_by' => $user->id, 'category' => 'health_plan', 'channel' => 'site', 'price_cents' => 2400, 'snapshot' => [], 'expires_at' => now()->addDay(), 'accepted_at' => now(), 'locked_at' => now()]);
        $subscription = Subscription::create(['number' => 'SF-LC', 'proposal_id' => $proposal->id, 'customer_id' => $customer->id, 'pet_id' => $pet->id, 'product_version_id' => $version->id,
            'originating_franchise_id' => $unit->id, 'category' => 'health_plan', 'price_cents' => 2400, 'contract_snapshot' => [], 'document_status' => 'valid', 'locked_at' => now()]);
        $lead = Lead::create(['name' => 'Mari', 'originating_franchise_id' => $unit->id, 'phone' => '5518996123456', 'status' => 'contacted', 'created_at' => now()->subDays(3)]);
        $other = Lead::create(['name' => 'Outra pessoa', 'originating_franchise_id' => $unit->id, 'phone' => '5518991112222']);
        $this->mock(\App\Services\Domain\EligibilityService::class)->shouldReceive('activationImpediments')->andReturn([]);

        app(ActivationService::class)->activate($subscription);

        $lead->refresh();
        $this->assertSame('converted', $lead->status);
        $this->assertSame('Maria Fictícia Souza', $lead->name);
        $this->assertSame('Mari', $lead->extra['nome_no_anuncio']);
        $this->assertSame('new', $other->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.converted_by_plan', 'entity_id' => $lead->id]);
    }
}

<?php

namespace Tests\Feature;

use App\Services\Domain\DomainException;
use App\Models\BenefitRule;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Services\Domain\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsActiveSubscription;
use Tests\TestCase;

/** Descontos e autorizações são informativos (Tudo pra Pet): não impedem publicar; coparticipação ainda impede. */
class CatalogDiscountPublishTest extends TestCase
{
    use BuildsActiveSubscription, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    private function draft(array $rule): ProductVersion
    {
        $product = Product::create(['name' => 'Plano com desconto', 'code' => (string) Str::uuid(), 'category' => 'health_plan']);
        $version = ProductVersion::create(['product_id' => $product->id, 'version' => 1, 'name' => 'Plano com desconto', 'price_cents' => 8600, 'billing_cycle' => 'monthly',
            'renewal_policy' => 'automatic', 'duration_months' => 12, 'species' => ['dog', 'cat'], 'contract_template' => 'Modelo para teste.']);
        BenefitRule::create(['product_version_id' => $version->id, 'code' => 'X', 'name' => 'Benefício', 'waiting_days' => 0, 'period_type' => 'contract_year'] + $rule);

        return $version;
    }

    public function test_discount_and_authorization_benefits_can_be_published(): void
    {
        $version = $this->draft(['coverage_type' => 'discount', 'discount_basis_points' => 1000, 'requires_authorization' => true]);
        $this->assertSame('published', app(CatalogService::class)->publish($version, $this->admin)->status);
    }

    public function test_copay_still_blocks_publishing(): void
    {
        $version = $this->draft(['coverage_type' => 'included', 'copay_cents' => 500]);
        $this->expectException(DomainException::class);
        app(CatalogService::class)->publish($version, $this->admin);
    }
}

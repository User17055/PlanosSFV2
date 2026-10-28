<?php

namespace Tests\Feature;

use App\Models\{ContractDocument, Customer, Franchise, Invoice, Pet, Product, ProductVersion, Proposal, Subscription, User};
use App\Services\Contracts\ContractService;
use App\Services\Domain\ActivationService;
use App\Services\Integrations\{VindiReconciler, VindiWebhookService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Bus, Http, Storage};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IntegrationContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['integrations.vindi.enabled' => true, 'integrations.vindi.api_key' => 'sandbox-key',
            'integrations.vindi.webhook_user' => 'integration', 'integrations.vindi.webhook_password' => str_repeat('a', 32),
            'integrations.vindi.provider_account' => 'central', 'integrations.contracts.require_antivirus' => false]);
        Bus::fake();
        $this->mock(ActivationService::class)->shouldReceive('activate')->andReturn([]);
    }

    public function test_final_pdf_is_private_frozen_and_idempotent(): void
    {
        $subscription = $this->subscription();
        $service = app(ContractService::class);
        $doc = $service->generateDigital($subscription, ['affirmative' => true, 'signature_method' => 'digital', 'ip' => '127.0.0.1']);
        $bytes = Storage::disk('local')->get($doc->storage_path);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertTrue($service->verifyIntegrity($doc));
        $this->assertEquals('valid', $subscription->fresh()->document_status);
        $subscription->customer->update(['name' => 'Nome alterado depois do aceite']);
        $again = $service->generateDigital($subscription->fresh(), ['affirmative' => true]);
        $this->assertSame($doc->id, $again->id);
        $this->assertSame(hash('sha256', $bytes), $again->sha256);
        $this->assertDatabaseCount('contract_documents', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_paper_draft_and_uploaded_scan_do_not_validate_themselves(): void
    {
        $subscription = $this->subscription();
        $actor = User::first();
        $service = app(ContractService::class);
        $draft = $service->generatePaperDraft($subscription, $actor->id);
        $this->assertSame('paper_draft', $draft->kind);
        $this->assertSame('pending', $subscription->fresh()->document_status);
        $file = UploadedFile::fake()->createWithContent('assinado.pdf', Storage::disk('local')->get($draft->storage_path));
        $scan = $service->uploadPaper($subscription, $file, $actor->id, ['signed_at' => now()->subMinute()->toIso8601String()]);
        $this->assertSame('pending', $scan->status);
        $this->assertSame('final_contract', $scan->kind);
        $this->assertSame('pending', $subscription->fresh()->document_status);
        $reviewed = $service->validatePaper($scan, true, $actor->id);
        $this->assertSame('valid', $reviewed->status);
        $this->assertSame($actor->id, $reviewed->validated_by);
        $this->assertNotNull($reviewed->accepted_at);
    }

    public function test_forged_webhook_is_rejected_and_authenticated_replay_is_deduplicated(): void
    {
        $service = app(VindiWebhookService::class);
        $body = json_encode(['event' => ['type' => 'bill_paid', 'data' => ['bill' => ['id' => 999, 'status' => 'paid']]]]);
        $request = Request::create('/webhooks/vindi', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        try {
            $service->receive($request);
            $this->fail('Forged webhook was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('integration_events', 0);
        $request->headers->set('Authorization', 'Basic '.base64_encode('integration:'.str_repeat('a', 32)));
        $first = $service->receive($request);
        $second = $service->receive($request);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('integration_events', 1);
        $this->assertArrayNotHasKey('status', $first->payload);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_provider_response_not_webhook_status_controls_payment(): void
    {
        $subscription = $this->subscription();
        $subscription->update(['vindi_subscription_id' => '123']);
        $invoice = Invoice::create(['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id,
            'originating_franchise_id' => $subscription->originating_franchise_id, 'external_id' => '456',
            'amount_cents' => 9900, 'due_at' => now()->addDay(), 'is_initial' => true]);
        Http::fake(['*/bills/456' => Http::response(['bill' => ['id' => 456, 'subscription' => ['id' => 123],
            'amount' => '99.00', 'status' => 'pending', 'updated_at' => now()->toIso8601String(), 'due_at' => now()->addDay()->toIso8601String(), 'charges' => []]])]);
        $result = app(VindiReconciler::class)->reconcileBill('456');
        $this->assertSame('pending', $result->status);
        $this->assertSame(0, $result->paid_cents);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('verified', $result->reconciliation_status);
    }

    public function test_non_pdf_upload_is_rejected(): void
    {
        $subscription = $this->subscription();
        $this->expectException(ValidationException::class);
        app(ContractService::class)->uploadPaper($subscription, UploadedFile::fake()->createWithContent('contrato.pdf', '<script>invalid</script>'), User::first()->id);
    }

    private function subscription(): Subscription
    {
        $franchise = Franchise::create(['name' => 'Unidade teste', 'code' => 'TEST']);
        $customer = Customer::create(['name' => 'Titular original', 'document' => '52998224725', 'email' => 'teste@example.test']);
        $actor = User::factory()->create(['role' => 'central_admin']);
        $pet = Pet::create(['customer_id' => $customer->id, 'name' => 'Lua', 'species' => 'dog']);
        $product = Product::create(['name' => 'Plano teste', 'code' => 'TEST', 'category' => 'health_plan']);
        $version = ProductVersion::create(['product_id' => $product->id, 'version' => 1, 'name' => 'Versão teste',
            'price_cents' => 9900, 'billing_cycle' => 'monthly', 'species' => ['dog'], 'contract_template' => 'Texto integral de teste.']);
        $snapshot = ['customer' => $customer->toArray(), 'pet' => $pet->toArray(), 'product' => $product->toArray(),
            'version' => $version->toArray(), 'benefits' => [], 'category' => 'health_plan'];
        $proposal = Proposal::create(['number' => 'PR-TEST', 'customer_id' => $customer->id, 'pet_id' => $pet->id,
            'product_version_id' => $version->id, 'originating_franchise_id' => $franchise->id, 'created_by' => $actor->id,
            'category' => 'health_plan', 'channel' => 'site', 'price_cents' => 9900, 'snapshot' => $snapshot, 'expires_at' => now()->addDay(), 'locked_at' => now()]);
        return Subscription::create(['number' => 'SF-TEST', 'proposal_id' => $proposal->id, 'customer_id' => $customer->id,
            'pet_id' => $pet->id, 'product_version_id' => $version->id, 'originating_franchise_id' => $franchise->id,
            'category' => 'health_plan', 'price_cents' => 9900, 'contract_snapshot' => $snapshot, 'locked_at' => now()]);
    }
}

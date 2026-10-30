<?php

namespace Tests\Feature;

use App\Services\Domain\ProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsActiveSubscription;
use Tests\TestCase;

/** Plano assinado mas sem o primeiro pagamento não aparece em Meu cuidado; a fatura continua em Faturas. */
class PortalUnpaidPlanHiddenTest extends TestCase
{
    use BuildsActiveSubscription, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    public function test_signed_plan_without_payment_is_not_listed_but_paid_plan_is(): void
    {
        $proposal = app(ProposalService::class)->create(['customer_id' => $this->customer->id, 'pet_id' => $this->pet->id,
            'product_version_id' => $this->version->id, 'originating_franchise_id' => $this->unit->id, 'channel' => 'site'], $this->client);
        $subscription = app(ProposalService::class)->accept($proposal, $this->client, ['accepted' => true]);

        $this->actingAs($this->client)->get(route('portal'))->assertOk()->assertDontSee('Versão teste');
        $this->get(route('portal.pet', $this->pet))->assertOk()->assertDontSee('Versão teste');
        $this->get(route('portal.plan', [$this->pet, $subscription]))->assertNotFound();
        $this->get(route('portal.invoices'))->assertOk()->assertSee('Versão teste');
        // O pet mostra que a proposta aguarda validação (e o botão de pagar), sem listar o plano como contratado.
        $this->get(route('portal'))->assertSee('Aguardando validação da proposta')->assertDontSee('Ainda sem plano contratado');
        $this->get(route('portal.pet', $this->pet))->assertSee('Aguardando validação da proposta')->assertSee('Pagar agora')->assertDontSee('ainda não tem um plano');

        $invoice = $subscription->invoices()->firstOrFail();
        app(\App\Services\Domain\FinancialService::class)->applyVerifiedInvoice($invoice, ['status' => 'paid', 'paid_cents' => $invoice->amount_cents,
            'paid_at' => now()->toIso8601String(), 'provider_updated_at' => now()->toIso8601String(), 'reference' => 'transaction-'.$invoice->id, 'source' => 'test_verified']);
        $this->get(route('portal'))->assertOk()->assertSee('Versão teste');
    }
}

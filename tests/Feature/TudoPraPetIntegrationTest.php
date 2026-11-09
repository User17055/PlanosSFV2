<?php

namespace Tests\Feature;

use App\Models\{IntegrationEvent, Invoice};
use App\Services\Domain\{CancellationService, FinancialService};
use App\Services\Integrations\{TudoPraPetException, TudoPraPetMembership};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsActiveSubscription;
use Tests\TestCase;

/** Grupo "Plano São Francisco" da Tudo pra Pet: entra com plano em vigor, sai no fim do plano ou no atraso após o fim do mês. */
class TudoPraPetIntegrationTest extends TestCase
{
    use BuildsActiveSubscription, RefreshDatabase;

    private const URL = 'https://tpp.example.test/functions/v1/partner-sao-francisco';
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $this->customer->update(['document' => '529.982.247-25', 'phone' => '(18) 99999-0000']);
        Http::preventStrayRequests();
        Http::fake([self::URL => function ($request) {
            $action = $request['action'];
            return $this->responses[$action] ?? Http::response(['ok' => true, 'status' => $action === 'activate' ? 'active' : 'inactive', 'account_created' => $action === 'activate'], 200);
        }]);
    }

    private function enable(): void
    {
        config(['integrations.tudoprapet.enabled' => true, 'integrations.tudoprapet.url' => self::URL, 'integrations.tudoprapet.api_key' => 'chave-de-teste']);
    }

    private function overdueInvoice(string $dueAt): Invoice
    {
        $s = $this->customer->subscriptions()->firstOrFail();
        return Invoice::create(['subscription_id' => $s->id, 'customer_id' => $this->customer->id, 'originating_franchise_id' => $s->originating_franchise_id,
            'amount_cents' => 10000, 'due_at' => $dueAt, 'status' => 'pending', 'is_initial' => false, 'external_id' => 'mensal-'.uniqid()]);
    }

    public function test_without_the_key_nothing_is_sent_and_the_task_stays_manual(): void
    {
        $this->activeSubscription();
        Http::assertNothingSent();
        $this->assertDatabaseHas('integration_events', ['provider' => 'tudoprapet', 'status' => 'manual']);
        $this->assertNull($this->customer->fresh()->tudoprapet_status);
    }

    public function test_activation_puts_the_tutor_in_the_group_with_the_agreed_data(): void
    {
        $this->enable();
        $this->activeSubscription();
        Http::assertSent(fn ($r) => $r->url() === self::URL && $r->hasHeader('x-api-key', 'chave-de-teste') && $r['action'] === 'activate'
            && $r['cpf'] === '52998224725' && $r['phone'] === '18999990000' && $r['email'] === 'customer@example.test' && $r['name'] === 'Tutor fictício');
        $this->assertSame('active', $this->customer->fresh()->tudoprapet_status);
        $this->assertSame(0, IntegrationEvent::where('provider', 'tudoprapet')->count(), 'Com a chave, não vira tarefa manual.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'tudoprapet.activated']);
    }

    public function test_late_payment_only_removes_after_the_end_of_the_due_month_and_paying_brings_back(): void
    {
        $this->enable();
        $this->activeSubscription();
        $membership = app(TudoPraPetMembership::class);
        // Vencida neste mês: ainda dentro do prazo do contrato (até o último dia do mês).
        $this->travelTo(now('America/Sao_Paulo')->startOfMonth()->addDays(20));
        $current = $this->overdueInvoice(now()->startOfMonth()->addDays(9));
        $this->assertSame('unchanged', $membership->sync($this->customer->fresh()));
        // Virou o mês sem pagar: sai do grupo.
        $this->travelTo(now('America/Sao_Paulo')->addMonthNoOverflow()->startOfMonth()->addDays(1));
        $this->assertSame('deactivated', $membership->sync($this->customer->fresh()));
        Http::assertSent(fn ($r) => $r['action'] === 'deactivate' && $r['cpf'] === '52998224725');
        $this->assertSame('inactive', $this->customer->fresh()->tudoprapet_status);
        // Pagou: volta sozinho.
        app(FinancialService::class)->applyVerifiedInvoice($current, ['status' => 'paid', 'paid_cents' => 10000, 'paid_at' => now()->toIso8601String(),
            'provider_updated_at' => now()->toIso8601String(), 'reference' => 'pago-'.$current->id, 'source' => 'test_verified']);
        $this->assertSame('active', $this->customer->fresh()->tudoprapet_status);
    }

    public function test_ended_plan_removes_and_a_tutor_already_out_of_the_group_is_not_an_error(): void
    {
        $this->enable();
        $s = $this->activeSubscription();
        $this->responses['deactivate'] = Http::response(['ok' => false, 'error' => 'member_not_found'], 404);
        $s->update(['status' => 'scheduled_cancellation', 'ends_at' => now()->subMinute()]);
        app(CancellationService::class)->finalizeDue();
        $this->assertSame('inactive', $this->customer->fresh()->tudoprapet_status);
    }

    public function test_refused_data_is_kept_for_the_team_and_temporary_failures_are_retried(): void
    {
        $this->enable();
        $this->responses['activate'] = Http::response(['ok' => false, 'error' => 'validation_error', 'fields' => ['phone']], 400);
        $this->activeSubscription();
        $customer = $this->customer->fresh();
        $this->assertNull($customer->tudoprapet_status);
        $this->assertStringContainsString('validation_error (phone)', $customer->tudoprapet_error);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tudoprapet.failed']);

        $this->responses['activate'] = Http::response(['ok' => false, 'error' => 'internal_error'], 503);
        $this->expectException(TudoPraPetException::class);
        app(TudoPraPetMembership::class)->sync($customer);
    }
}

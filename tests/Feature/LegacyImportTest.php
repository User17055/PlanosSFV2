<?php

namespace Tests\Feature;

use App\Models\{Customer, Franchise, Invoice, LegacyPendingItem, Pet, PetProductSlot, Product, Subscription, User};
use App\Services\Domain\{ActivationService, EligibilityService, LegacyImport};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Notification, Queue};
use Tests\TestCase;

/** Base antiga: contratos ativos ligados à Vindi, pets do SimplesVet na ficha e os itens sem pet em "Pets para escolher". */
class LegacyImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Franchise::create(['name' => 'Manoel Goulart', 'code' => 'CENTRAL', 'active' => true]);
        $this->admin = User::factory()->create(['name' => 'Admin', 'role' => 'central_admin', 'active' => true]);
        Customer::create(['name' => 'ANA DA VINDI', 'document' => '52998224725', 'vindi_customer_id' => 'v1']);
    }

    private function data(): array
    {
        return ['plans' => [['name' => 'Plano Super Econômico', 'category' => 'health_plan', 'price_cents' => 5200], ['name' => 'Pacote Banho Quinzenal', 'category' => 'package', 'price_cents' => 13200]],
            'customers' => [
                ['vindi_customer_id' => 'v1', 'cpf' => '52998224725', 'name_vindi' => 'ANA DA VINDI', 'name_sv' => 'ANA', 'simplesvet_id' => '10', 'phone' => '(18) 99999-0000',
                    'email' => 'ana@example.test', 'address' => ['formatted' => 'RUA A, 10', 'neighborhood' => 'CENTRO', 'city' => 'PRESIDENTE PRUDENTE', 'state' => 'SP', 'zip' => '19010-000'],
                    'pets' => [['simplesvet_id' => '100', 'name' => 'Rex', 'species' => 'dog', 'breed' => 'SRD', 'sex' => 'male', 'birth_date' => null, 'color' => 'Preto', 'neutered' => true],
                        ['simplesvet_id' => '101', 'name' => 'Mia', 'species' => 'cat', 'breed' => null, 'sex' => 'female', 'birth_date' => '2020-01-05', 'color' => null, 'neutered' => null]],
                    'items' => [
                        ['vindi_subscription_id' => '500', 'vindi_product_item_id' => '9001', 'plan' => 'Plano Super Econômico', 'price_cents' => 5200, 'started_at' => '2025-03-10', 'pet_simplesvet_id' => '100'],
                        ['vindi_subscription_id' => '500', 'vindi_product_item_id' => '9002', 'plan' => 'Plano Super Econômico', 'price_cents' => 5200, 'started_at' => '2025-05-01', 'pet_simplesvet_id' => null, 'reason' => 'escolher o pet', 'hint' => 'na planilha como "MIMI"'],
                        ['vindi_subscription_id' => '501', 'vindi_product_item_id' => '9003', 'plan' => 'Pacote Banho Quinzenal', 'price_cents' => 13200, 'started_at' => '2026-02-01', 'pet_simplesvet_id' => '100'],
                    ]],
                ['vindi_customer_id' => 'v2', 'cpf' => null, 'name_vindi' => 'BRUNO DA VINDI', 'pets' => [],
                    'items' => [['vindi_subscription_id' => '502', 'vindi_product_item_id' => '9004', 'plan' => 'Plano Super Econômico', 'price_cents' => 4800, 'started_at' => null, 'pet_simplesvet_id' => null, 'reason' => 'achar o cliente']]],
            ]];
    }

    public function test_import_creates_active_contracts_linked_to_vindi_without_email_commission_or_vindi_writes(): void
    {
        Queue::fake();
        Notification::fake();
        $import = app(LegacyImport::class);
        $dry = $import->run($this->data(), $this->admin, false);
        $this->assertSame([2, 2, 1, 1, 2], [$dry['contratos_criados'], $dry['pets_para_escolher'], $dry['clientes_criados'], $dry['clientes_completados'], $dry['pets_criados']]);
        $this->assertSame([0, 1, 0], [Subscription::count(), Customer::count(), Pet::count()], 'A simulação não grava nada.');

        $report = $import->run($this->data(), $this->admin, true);
        $this->assertSame($dry, $report);
        $ana = Customer::where('vindi_customer_id', 'v1')->sole();
        $this->assertSame(['(18) 99999-0000', 'ana@example.test', '10', 'ANA DA VINDI'], [$ana->phone, $ana->email, $ana->simplesvet_id, $ana->name], 'Completa só o que estava vazio.');
        $rex = Pet::where('simplesvet_id', '100')->sole();
        $this->assertSame([$ana->id, 'dog', 'Preto', true], [$rex->customer_id, $rex->species, $rex->color, $rex->neutered]);
        $plan = Subscription::where('vindi_product_item_id', '9001')->sole();
        $this->assertSame(['active', 'pending', '500', 5200, $rex->id], [$plan->status, $plan->document_status, $plan->vindi_subscription_id, $plan->price_cents, $plan->pet_id]);
        $this->assertNotNull($plan->imported_at);
        $this->assertSame('2025-03-10', $plan->effective_start_at->timezone('America/Sao_Paulo')->toDateString());
        $this->assertSame(['paper', 'paper_issued', null], [$plan->proposal->signature_method, $plan->proposal->status, $plan->proposal->accepted_at]);
        $this->assertSame('package', Subscription::where('vindi_product_item_id', '9003')->sole()->category, 'O mesmo pet pode ter plano e pacote de banho.');
        $this->assertSame(['occupied', 'occupied'], PetProductSlot::where('pet_id', $rex->id)->orderBy('product_category')->pluck('state')->all());
        $this->assertSame(['legacy', false], [$plan->productVersion->status, $plan->productVersion->product->active]);
        $this->assertSame(2, Product::where('code', 'like', 'BA-%')->count());
        $this->assertNull(Customer::where('vindi_customer_id', 'v2')->sole()->document);
        $this->assertSame(['9002', '9004'], LegacyPendingItem::orderBy('id')->pluck('vindi_product_item_id')->all());
        $this->assertSame(0, Invoice::count());
        Queue::assertNotPushed(\App\Jobs\SyncVindiCustomerJob::class);
        Notification::assertNothingSent();

        // Rodar de novo não duplica nada.
        $again = $import->run($this->data(), $this->admin, true);
        $this->assertSame([0, 0, 4], [$again['contratos_criados'], $again['pets_para_escolher'], $again['itens_ja_importados']]);
        $this->assertSame(2, Subscription::count());
    }

    public function test_pet_linked_later_gets_its_share_of_a_bill_already_reconciled(): void
    {
        config(['integrations.vindi.enabled' => true, 'integrations.vindi.api_key' => 'test-only']);
        app(LegacyImport::class)->run($this->data(), $this->admin, true);
        $bill = ['id' => 700, 'subscription' => ['id' => 500], 'customer' => ['id' => 1], 'amount' => '104.0', 'status' => 'paid', 'url' => null,
            'updated_at' => now()->subDay()->toIso8601String(), 'due_at' => now()->subDays(2)->toDateString(), 'charges' => [['status' => 'paid', 'paid_at' => now()->subDay()->toIso8601String()]],
            'bill_items' => [['amount' => '52.0', 'product_item' => ['id' => 9001]], ['amount' => '52.0', 'product_item' => ['id' => 9002]]]];
        Http::fake(['*/bills*' => Http::response(['bills' => [$bill]])]);
        $rex = Subscription::where('vindi_product_item_id', '9001')->sole();
        $reconciler = app(\App\Services\Integrations\VindiReconciler::class);
        $reconciler->reconcileSubscription($rex);
        $this->assertSame([$rex->id], Invoice::where('external_id', '700')->pluck('subscription_id')->all(), 'O item ainda sem pet fica de fora.');
        $mia = app(LegacyImport::class)->link(LegacyPendingItem::where('vindi_product_item_id', '9002')->sole(), Pet::where('simplesvet_id', '101')->sole(), $this->admin);
        $reconciler->reconcileSubscription($rex->fresh());
        $this->assertSame([[$rex->id, 5200, 'paid'], [$mia->id, 5200, 'paid']], Invoice::where('external_id', '700')->orderBy('subscription_id')->get()
            ->map(fn ($i) => [$i->subscription_id, $i->amount_cents, $i->status])->all(), 'A Mia, ligada depois, ganha a parte dela da fatura já paga.');
        $this->assertSame(2, \App\Models\Payment::count(), 'O pagamento do Rex não é duplicado.');
        $this->assertSame(0, $reconciler->reconcileSubscription($rex->fresh()), 'Com os dois pets conferidos, a fatura volta a ser pulada.');
    }

    public function test_old_plan_is_not_suspended_by_missing_entry_contract_or_birth_date_and_stays_out_of_tudo_pra_pet(): void
    {
        app(LegacyImport::class)->run($this->data(), $this->admin, true);
        $plan = Subscription::where('vindi_product_item_id', '9001')->sole();
        $codes = array_column(app(EligibilityService::class)->activationImpediments($plan), 'code');
        $this->assertSame(['FINANCIAL_VERIFICATION_PENDING'], $codes, 'Sem entrada, contrato assinado ou data de nascimento: só falta a conferência da Vindi.');
        app(ActivationService::class)->activate($plan);
        $this->assertSame('active', $plan->fresh()->status);
        $this->assertFalse(app(\App\Services\Integrations\TudoPraPetMembership::class)->desired($plan->customer));
        // Até 5 dias de atraso é carência; depois suspende, como em qualquer plano (o passe diário pega o boleto parado), e volta quando quita.
        $bill = Invoice::create(['subscription_id' => $plan->id, 'customer_id' => $plan->customer_id, 'originating_franchise_id' => $plan->originating_franchise_id,
            'amount_cents' => 5200, 'due_at' => now()->subDays(3), 'status' => 'pending', 'reconciliation_status' => 'verified']);
        app(ActivationService::class)->activate($plan);
        $this->assertSame('active', $plan->fresh()->status);
        $bill->update(['due_at' => now()->subDays(10)]);
        $this->artisan('plans:refresh-status')->expectsOutput('2 contrato(s) com a situação atualizada.')->assertSuccessful();
        $this->assertSame(['suspended', 'suspended'], Subscription::where('customer_id', $plan->customer_id)->whereNotNull('activated_at')->orderBy('id')->pluck('status')->all(), 'Atraso no plano bloqueia também o pacote do cliente.');
        $bill->update(['paid_cents' => 5200, 'status' => 'paid']);
        $this->artisan('plans:refresh-status')->expectsOutput('2 contrato(s) com a situação atualizada.')->assertSuccessful();
        $this->assertSame('active', $plan->fresh()->status, 'Quitou: o plano antigo volta mesmo com a conferência da Vindi ainda por rodar.');
    }

    public function test_team_chooses_the_pet_from_the_dashboard_and_the_contract_is_born_linked(): void
    {
        app(LegacyImport::class)->run($this->data(), $this->admin, true);
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('Pets para escolher: 2')->assertSee(route('legacy.pending'), false);
        $this->get(route('legacy.pending'))->assertOk()->assertSee('ANA DA VINDI')->assertSee('na planilha como &quot;MIMI&quot;', false)
            ->assertSee('Este cliente não tem pet na ficha');
        $mia = Pet::where('simplesvet_id', '101')->sole();
        $rex = Pet::where('simplesvet_id', '100')->sole();
        $item = LegacyPendingItem::where('vindi_product_item_id', '9002')->sole();
        $this->post(route('legacy.pending.link', $item), ['pet_id' => $rex->id])->assertSessionHas('error', 'Este pet já tem um plano desta categoria.');
        $this->post(route('legacy.pending.link', $item), ['pet_id' => $mia->id])->assertSessionHas('success');
        $plan = Subscription::where('vindi_product_item_id', '9002')->sole();
        $this->assertSame([$mia->id, '500', 'active'], [$plan->pet_id, $plan->vindi_subscription_id, $plan->status]);
        $this->assertSame(['linked', $plan->id], [$item->fresh()->resolution, $item->fresh()->subscription_id]);
        $this->post(route('legacy.pending.link', $item), ['pet_id' => $mia->id])->assertSessionHas('error', 'Este item já foi resolvido.');
        $other = LegacyPendingItem::where('vindi_product_item_id', '9004')->sole();
        $this->post(route('legacy.pending.dismiss', $other), ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->post(route('legacy.pending.dismiss', $other), ['reason' => 'Cobrança não é de pet'])->assertSessionHas('success');
        $this->assertSame('dismissed', $other->fresh()->resolution);
        // O contrato antigo fica na ficha, mas não é proposta.
        $this->get(route('proposals.index'))->assertOk()->assertDontSee($plan->number);
        $this->get(route('customers.show', $plan->customer_id))->assertOk()->assertSee($plan->number)->assertDontSee('Histórico de propostas');
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Pets para escolher:');
        // Planos gerais contam a base antiga; planos novos e vendas, não.
        $general = app(\App\Services\Reports\DashboardService::class);
        $report = $general->report($this->admin, $general->filters($this->admin, []));
        $this->assertSame([2, 0], [$report['summary']['active_count'], $report['summary']['sold_count']], 'Planos de saúde por padrão; o banho fica em Pacotes.');
        $this->assertSame(0, $general->report($this->admin, $general->filters($this->admin, ['base' => 'novos']))['summary']['active_count']);
        $this->assertSame([['Plano Super Econômico', 2]], $report['plans']->map(fn ($p) => [$p['name'], $p['active']])->all(), 'No painel, o plano aparece sem "(base antiga)".');
        $this->assertSame(['Plano Econômico', 'Pacote Banho Quinzenal', 'Plano VIP'], array_map([\App\Services\Reports\DashboardService::class, 'planLabel'],
            ['Plano Econômico + 2 hemogramas (base antiga)', 'Pacote Banho Quinzenal + taxa (base antiga)', 'Plano VIP (base antiga)']), 'Variação conta no plano principal.');    }

    public function test_vindi_cancellations_close_old_contracts_and_feed_the_dashboard_without_writing_to_vindi(): void
    {
        config(['integrations.vindi.enabled' => true, 'integrations.vindi.api_key' => 'test-only']);
        app(LegacyImport::class)->run($this->data(), $this->admin, true);
        $item = fn ($id, $name, $price) => ['id' => $id, 'status' => 'active', 'product' => ['name' => $name], 'pricing_schema' => ['price' => $price]];
        $canceled = [
            // A assinatura 500 (Rex e o pet ainda por escolher) foi cancelada na Vindi depois da importação.
            ['id' => 500, 'customer' => ['id' => 'v1'], 'plan' => ['name' => 'PLANO SUPER ECONOMICO'], 'start_at' => '2025-03-10T00:00:00-03:00', 'cancel_at' => '2026-10-05T10:00:00-03:00',
                'product_items' => [$item(9001, 'Plano Super Econômico⠀', '52.0'), $item(9002, 'Plano Super Econômico⠀', '52.0')]],
            // Cancelada, mas o cliente seguiu com a 500 (plano do mesmo tipo): não é saída.
            ['id' => 700, 'customer' => ['id' => 'v1'], 'plan' => ['name' => 'PLANO SUPER ECONOMICO'], 'start_at' => '2025-01-01T00:00:00-03:00', 'cancel_at' => '2026-02-01T09:00:00-03:00',
                'product_items' => [$item(7001, 'Plano Super Econômico', '48.0')]],
            // Cliente que saiu antes da importação; "GASTOS EXTRAS" não é pet.
            ['id' => 701, 'customer' => ['id' => 'v9'], 'plan' => ['name' => 'PLANO ECONOMICO FELINO'], 'start_at' => '2025-06-01T00:00:00-03:00', 'cancel_at' => '2026-06-15T09:00:00-03:00',
                'product_items' => [$item(7101, 'PLANO ECONOMICO FELINO', '24.0'), $item(7102, 'GASTOS EXTRAS', '30.0')]],
            ['id' => 702, 'customer' => ['id' => 'v9'], 'plan' => ['name' => 'teste'], 'start_at' => '2025-06-01T00:00:00-03:00', 'cancel_at' => '2026-06-15T09:00:00-03:00', 'product_items' => [$item(7201, 'Plano A', '1.0')]],
        ];
        \Illuminate\Support\Facades\Http::fake(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/subscriptions') ? \Illuminate\Support\Facades\Http::response(['subscriptions' => $canceled]) : \Illuminate\Support\Facades\Http::response([], 500));
        $this->artisan('plans:sync-vindi-cancellations')->expectsOutputToContain('contratos_encerrados: 1')->assertSuccessful();
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => $request->method() === 'GET');
        \Illuminate\Support\Facades\Http::assertNotSent(fn ($request) => $request->method() !== 'GET');

        $rex = Subscription::where('vindi_product_item_id', '9001')->sole();
        $this->assertSame(['cancelled', '2026-10-05'], [$rex->status, $rex->cancelled_at->timezone('America/Sao_Paulo')->toDateString()]);
        $this->assertSame('released', PetProductSlot::where('subscription_id', $rex->id)->value('state'));
        $this->assertSame('cancelled_in_vindi', LegacyPendingItem::where('vindi_product_item_id', '9002')->value('resolution'));
        $this->assertSame(['7001' => true, '7101' => false, '9001' => false, '9002' => false], \App\Models\VindiCancellation::orderBy('vindi_product_item_id')->pluck('replaced', 'vindi_product_item_id')->all());
        $this->assertSame('active', Subscription::where('vindi_product_item_id', '9003')->value('status'), 'O pacote de outra assinatura continua.');

        // No painel, quem saiu conta uma vez só (o 9001 pelo contrato daqui), e a assinatura refeita não conta.
        // O nome em maiúsculas da Vindi dá lugar ao do cadastro de planos daqui.
        Product::create(['name' => 'Econômico Felino', 'code' => 'SF-FEL', 'category' => 'health_plan', 'active' => true]);
        $dashboard = app(\App\Services\Reports\DashboardService::class);
        $c = $dashboard->report($this->admin, $dashboard->filters($this->admin, ['date_from' => '2026-01-01', 'date_to' => now('America/Sao_Paulo')->toDateString()]))['growth']['cancellations'];
        $this->assertSame([3, 1], [$c['count'], $c['replaced']]);
        $this->assertSame([['Plano Super Econômico', 2], ['Plano Econômico Felino', 1]], array_map(fn ($p) => [$p['name'], $p['count']], $c['plans']));
        $novos = $dashboard->report($this->admin, $dashboard->filters($this->admin, ['base' => 'novos', 'date_from' => '2026-01-01', 'date_to' => now('America/Sao_Paulo')->toDateString()]));
        $this->assertSame(0, $novos['growth']['cancellations']['count'], 'Planos novos não veem a base antiga.');
        // Rodar de novo não duplica nem reabre nada.
        $this->artisan('plans:sync-vindi-cancellations')->expectsOutputToContain('contratos_encerrados: 0')->assertSuccessful();
        $this->assertSame(4, \App\Models\VindiCancellation::count());
    }

    public function test_only_central_admin_and_unit_managers_choose_pets(): void
    {
        app(LegacyImport::class)->run($this->data(), $this->admin, true);
        $item = LegacyPendingItem::firstOrFail();
        $seller = User::factory()->create(['role' => 'sales', 'active' => true]);
        $this->actingAs($seller)->get(route('legacy.pending'))->assertForbidden();
        $this->post(route('legacy.pending.link', $item), ['pet_id' => Pet::firstOrFail()->id])->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Pets para escolher');
    }
}

<?php

namespace Tests\Feature;

use App\Models\{Customer, Franchise, Invoice, LegacyPendingItem, Pet, PetProductSlot, Product, Subscription, User};
use App\Services\Domain\{ActivationService, EligibilityService, LegacyImport};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Notification, Queue};
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

    public function test_old_plan_is_not_suspended_by_missing_entry_contract_or_birth_date_and_stays_out_of_tudo_pra_pet(): void
    {
        app(LegacyImport::class)->run($this->data(), $this->admin, true);
        $plan = Subscription::where('vindi_product_item_id', '9001')->sole();
        $codes = array_column(app(EligibilityService::class)->activationImpediments($plan), 'code');
        $this->assertSame(['FINANCIAL_VERIFICATION_PENDING'], $codes, 'Sem entrada, contrato assinado ou data de nascimento: só falta a conferência da Vindi.');
        app(ActivationService::class)->activate($plan);
        $this->assertSame('active', $plan->fresh()->status);
        $this->assertFalse(app(\App\Services\Integrations\TudoPraPetMembership::class)->desired($plan->customer));
        // Atraso de verdade suspende, como em qualquer plano.
        Invoice::create(['subscription_id' => $plan->id, 'customer_id' => $plan->customer_id, 'originating_franchise_id' => $plan->originating_franchise_id,
            'amount_cents' => 5200, 'due_at' => now()->subDays(10), 'status' => 'pending', 'reconciliation_status' => 'verified']);
        app(ActivationService::class)->activate($plan);
        $this->assertSame('suspended', $plan->fresh()->status);
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
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Pets para escolher:');
        // Planos gerais contam a base antiga; planos novos e vendas, não.
        $general = app(\App\Services\Reports\DashboardService::class);
        $report = $general->report($this->admin, $general->filters($this->admin, []));
        $this->assertSame([2, 0], [$report['summary']['active_count'], $report['summary']['sold_count']], 'Planos de saúde por padrão; o banho fica em Pacotes.');
        $this->assertSame(0, $general->report($this->admin, $general->filters($this->admin, ['base' => 'novos']))['summary']['active_count']);
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

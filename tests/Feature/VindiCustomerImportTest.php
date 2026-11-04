<?php

namespace Tests\Feature;

use App\Models\{AuditLog,Customer,Franchise,User};
use App\Services\Integrations\VindiCustomerImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http,Notification};
use Tests\TestCase;

/** Só o cadastro de quem tem plano ativo vem da Vindi: sem pet, sem contrato, sem nada gravado lá. */
class VindiCustomerImportTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'plans:import-vindi-customers';

    private array $plans = [['id' => 1, 'name' => 'PLANO ECONOMICO'], ['id' => 2, 'name' => 'PACOTE BANHO SEMANAL'], ['id' => 3, 'name' => 'SERVIÇOS'], ['id' => 4, 'name' => 'TESTE - SEMANAL'], ['id' => 5, 'name' => 'PLANO teste']];
    private array $customers;
    private int $failOn = 0;
    private int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.vindi.enabled' => true, 'integrations.vindi.api_key' => 'test-only', 'integrations.vindi.base_url' => 'https://vindi.test/api/v1']);
        Franchise::create(['name' => 'Canal central', 'code' => 'CENTRAL', 'active' => true]);
        Notification::fake();
        // Clientes sem plano vêm antes, para os que interessam caírem na segunda página da Vindi.
        $filler = array_map(fn ($id) => ['id' => $id, 'status' => 'active', 'name' => 'Sem plano '.$id, 'registry_code' => '52998224725'], range(100, 149));
        $this->customers = [...$filler,
            ['id' => 10, 'status' => 'active', 'name' => 'Maria da Silva', 'registry_code' => '111.444.777-35', 'email' => 'maria@example.test', 'phones' => [['phone_type' => 'landline', 'number' => '551832220000'], ['phone_type' => 'mobile', 'number' => '5518999990000']],
                'address' => ['street' => 'Rua A', 'number' => '10', 'additional_details' => 'Casa', 'neighborhood' => 'Centro', 'city' => 'Prudente', 'state' => 'sp', 'zipcode' => '19010000']],
            ['id' => 11, 'status' => 'active', 'name' => 'Pet Delivery LTDA', 'registry_code' => '09.255.197/0001-84'],
            ['id' => 12, 'status' => 'active', 'name' => 'Ana Repetida', 'registry_code' => '39053344705'],
            ['id' => 13, 'status' => 'active', 'name' => 'Ana Repetida 2', 'registry_code' => '390.533.447-05'],
            ['id' => 14, 'status' => 'active', 'name' => 'Já Existe Aqui', 'registry_code' => '15350946056'],
            ['id' => 16, 'status' => 'active', 'name' => 'Outro Vinculo', 'registry_code' => '28625587887'],
            ['id' => 18, 'status' => 'active', 'name' => 'Sem Email', 'registry_code' => '98765432100', 'email' => 'nao-e-email', 'phones' => [], 'address' => null],
            ['id' => 17, 'status' => 'active', 'name' => 'Só Serviços', 'registry_code' => '74682489070'],
        ];
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (++$this->calls === $this->failOn) {
                return Http::response([], 500);
            }
            $path = parse_url($request->url(), PHP_URL_PATH);
            // A listagem não traz o 15 nem o 19; consultados direto, um está arquivado e o outro, ativo.
            if (preg_match('~/customers/(\d+)$~', $path, $id)) {
                return Http::response(['customer' => [
                    '15' => ['id' => 15, 'status' => 'archived', 'name' => 'Arquivada', 'registry_code' => '12345678909'],
                    '19' => ['id' => 19, 'status' => 'active', 'name' => 'Fora Da Listagem', 'registry_code' => '12345678909'],
                ][$id[1]]]);
            }
            $resource = basename($path);
            $query = (string) ($request->data()['query'] ?? '');
            $page = (int) ($request->data()['page'] ?? 1);
            $rows = match ($resource) {
                'plans' => $this->plans,
                'customers' => $this->customers,
                // O cliente 10 tem dois pets no plano; o 17 só tem "Serviços".
                'subscriptions' => str_contains($query, 'plan_id:1') ? array_map(fn ($id, $subscription) => ['id' => $subscription, 'customer' => ['id' => $id]], [10, 10, 11, 12, 13, 14, 15, 16, 18, 19], range(1, 10))
                    : (str_contains($query, 'plan_id:3') ? [['id' => 90, 'customer' => ['id' => 17]]] : []),
            };
            return Http::response([$resource => array_slice($rows, ($page - 1) * 50, 50)]);
        });
    }

    public function test_only_real_plans_count(): void
    {
        foreach (['PLANO ECONOMICO', 'plano vip', ' PLANO A', 'PLANO ECONOMICO + 2 HEMOGRAMAS'] as $name) {
            $this->assertTrue(VindiCustomerImport::isPlan($name), $name);
        }
        foreach (['PACOTE BANHO QUINZENAL', 'SERVIÇOS', 'DIREITOS DE USO DE MARCA', 'teste', 'TESTE - SEMANAL', 'PLANO teste', 'PLANOS'] as $name) {
            $this->assertFalse(VindiCustomerImport::isPlan($name), $name);
        }
    }

    public function test_without_apply_it_only_reports_and_writes_nothing(): void
    {
        $local = Customer::create(['name' => 'Já Existe Aqui', 'document' => '15350946056']);
        $this->artisan(self::COMMAND)->assertSuccessful()
            ->expectsOutputToContain('PLANO ECONOMICO')->expectsOutputToContain('Seriam criados: 4 (com e-mail: 1, com telefone: 1, com endereço: 1)')->expectsOutputToContain('Seriam ligados a um cadastro existente: 1')->expectsOutputToContain('Nada foi gravado')->doesntExpectOutputToContain('maria@example.test')->doesntExpectOutputToContain('11144477735');
        $this->assertDatabaseCount('customers', 1);
        $this->assertNull($local->fresh()->vindi_customer_id);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_apply_brings_only_the_registration_of_who_has_an_active_plan(): void
    {
        $here = Customer::create(['name' => 'Já Existe Aqui', 'document' => '15350946056', 'email' => 'mantido@example.test']);
        $other = Customer::create(['name' => 'Outro Vinculo', 'document' => '28625587887', 'vindi_customer_id' => '999']);

        $report = $this->runImport();
        $this->assertSame(['found' => 9, 'created' => 3, 'linked' => 1, 'existing' => 0], array_intersect_key($report, array_flip(['found', 'created', 'linked', 'existing'])));
        $this->assertSame(['email' => 1, 'phone' => 1, 'address' => 1], $report['filled']);
        $this->assertSame(['PLANO ECONOMICO' => 10], $report['plans']);
        $this->assertSame(['PACOTE BANHO SEMANAL', 'SERVIÇOS', 'TESTE - SEMANAL', 'PLANO teste'], $report['excluded']);
        $this->assertSame(['15'], $report['skipped'][VindiCustomerImport::ARCHIVED]);
        $this->assertSame(['11'], $report['skipped'][VindiCustomerImport::INVALID]);
        $this->assertSame(['12', '13'], $report['skipped'][VindiCustomerImport::REPEATED]);
        $this->assertSame(['16'], $report['skipped'][VindiCustomerImport::CONFLICT]);

        $maria = Customer::where('document', '11144477735')->sole();
        $this->assertSame(['Maria da Silva', 'maria@example.test', '(18) 99999-0000', '10'], [$maria->name, $maria->email, $maria->phone, $maria->vindi_customer_id]);
        $this->assertSame(['street' => 'Rua A', 'number' => '10', 'complement' => 'Casa', 'neighborhood' => 'Centro', 'city' => 'Prudente', 'state' => 'SP', 'zip' => '19010000', 'formatted' => 'Rua A, 10, Casa'], $maria->address);
        $this->assertSame(Franchise::where('code', 'CENTRAL')->value('id'), $maria->originating_franchise_id);
        $this->assertSame([0, 0, 0], [$maria->pets()->count(), $maria->subscriptions()->count(), $maria->invoices()->count()]);

        // Dado ruim da Vindi não entra, e cadastro que já existia só ganha o vínculo.
        $empty = Customer::where('document', '98765432100')->sole();
        $this->assertSame([null, null, null], [$empty->email, $empty->phone, $empty->address]);
        $this->assertSame(['mantido@example.test', 'Já Existe Aqui'], [$here->fresh()->email, $here->fresh()->name]);
        $this->assertSame('14', $here->fresh()->vindi_customer_id);
        $this->assertSame('999', $other->fresh()->vindi_customer_id);
        $this->assertDatabaseMissing('customers', ['vindi_customer_id' => '17']);

        $this->assertSame('19', Customer::where('document', '12345678909')->sole()->vindi_customer_id);
        $this->assertSame(3, AuditLog::where('action', 'customer.imported_from_vindi')->count());
        $this->assertSame(1, AuditLog::where('action', 'customer.linked_to_vindi')->count());
        Notification::assertNothingSent();
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_running_again_does_not_duplicate(): void
    {
        $this->runImport();
        $before = Customer::count();
        $report = $this->runImport();
        $this->assertSame([0, 0, 5], [$report['created'], $report['linked'], $report['existing']]);
        $this->assertSame($before, Customer::count());
    }

    public function test_vindi_failure_midway_writes_nothing(): void
    {
        $this->failOn = 4; // a segunda página de clientes, depois de ler planos e assinaturas
        $this->assertThrows(fn () => $this->runImport());
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_imported_customer_opens_in_the_staff_screens(): void
    {
        $this->runImport();
        $admin = User::factory()->create(['role' => 'central_admin', 'active' => true]);
        foreach (Customer::all() as $customer) {
            $this->actingAs($admin)->get(route('customers.show', $customer))->assertOk()->assertSee($customer->name);
        }
        $this->actingAs($admin)->get(route('customers.index'))->assertOk()->assertSee('Maria da Silva');
    }

    private function runImport(): array
    {
        return app(VindiCustomerImport::class)->run(true);
    }
}

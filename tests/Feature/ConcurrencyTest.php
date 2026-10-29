<?php

namespace Tests\Feature;

use App\Models\BenefitUsage;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\IntegrationEvent;
use App\Models\Subscription;
use App\Services\Domain\BenefitService;
use App\Services\Domain\ProposalService;
use App\Services\Integrations\VindiWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsActiveSubscription;
use Tests\TestCase;

/**
 * Concorrência real: cada operação roda num processo PHP próprio, com conexão própria ao MySQL
 * (tests/Concurrency/worker.php). Por isso os dados são gravados de fato, sem a transação do
 * RefreshDatabase, e o banco é recriado antes e depois.
 *
 * Este processo segura a linha disputada até todos os filhos estarem esperando por ela; ao soltar, eles
 * disputam a mesma linha, como duas recepções no mesmo segundo.
 */
class ConcurrencyTest extends TestCase
{
    use BuildsActiveSubscription;

    private const WEBHOOK_USER = 'vindi-teste';

    private const WEBHOOK_PASSWORD = 'senha-de-teste-com-mais-de-24-caracteres';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concorrência real só no MySQL (job da CI).');
        }
        Artisan::call('migrate:fresh');
        $this->setUpScenario();
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Artisan::call('migrate:fresh');
        }
        parent::tearDown();
    }

    public function test_recepcoes_de_duas_franquias_disputando_a_ultima_unidade_geram_uma_so_reserva(): void
    {
        $sub = $this->activeSubscription();
        $other = Franchise::create(['name' => 'Unidade B', 'code' => 'B']);
        app(BenefitService::class)->reserve($sub, $this->rule, $this->admin, $this->unit->id, 1, 'primeira');
        $tasks = [$this->reserveTask($sub, 'ultima-1'), $this->reserveTask($sub, 'ultima-2', $other->id), $this->reserveTask($sub, 'ultima-3')];

        $results = $this->race($tasks, fn () => Customer::whereKey($sub->customer_id)->lockForUpdate()->first(), 'for update', 3);

        $this->assertSame(2, BenefitUsage::where('benefit_rule_id', $this->rule->id)->count(), json_encode($results));
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(['BENEFIT_LIMIT_REACHED', 'BENEFIT_LIMIT_REACHED'], array_values(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'code')), json_encode($results));
    }

    public function test_mesma_chave_de_reserva_em_paralelo_devolve_a_mesma_reserva_a_todos(): void
    {
        $sub = $this->activeSubscription();

        $results = $this->race(array_fill(0, 3, $this->reserveTask($sub, 'clique-duplo')), fn () => Customer::whereKey($sub->customer_id)->lockForUpdate()->first(), 'for update', 3);

        $this->assertSame(1, BenefitUsage::count(), json_encode($results));
        $this->assertSame(array_fill(0, 3, ['ok' => true, 'usage_id' => BenefitUsage::value('id')]), $results);
    }

    public function test_dois_estornos_simultaneos_registram_um_so_e_o_outro_recebe_a_mensagem(): void
    {
        $sub = $this->activeSubscription();
        $service = app(BenefitService::class);
        $usage = $service->consume($service->reserve($sub, $this->rule, $this->admin, $this->unit->id, 1, 'para-estornar'), $this->admin);
        $task = ['op' => 'reverse', 'usage_id' => $usage->id, 'user_id' => $this->admin->id];

        $results = $this->race([$task, $task], fn () => Customer::whereKey($sub->customer_id)->lockForUpdate()->first(), 'for update', 2);

        $this->assertSame(1, BenefitUsage::where('status', 'reversal')->count(), json_encode($results));
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(['REVERSAL_EXCEEDS_USAGE'], array_values(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'code')), json_encode($results));
    }

    public function test_aceite_repetido_em_paralelo_gera_um_so_contrato(): void
    {
        $proposal = app(ProposalService::class)->create(['customer_id' => $this->customer->id, 'pet_id' => $this->pet->id,
            'product_version_id' => $this->version->id, 'originating_franchise_id' => $this->unit->id, 'channel' => 'site'], $this->client);
        $task = ['op' => 'accept', 'proposal_id' => $proposal->id, 'user_id' => $this->client->id];

        $results = $this->race([$task, $task, $task], fn () => Customer::whereKey($this->customer->id)->lockForUpdate()->first(), 'for update', 3);

        $this->assertSame(1, Subscription::count(), json_encode($results));
        $this->assertSame(array_fill(0, 3, ['ok' => true, 'subscription_id' => Subscription::value('id')]), $results);
    }

    public function test_duas_propostas_do_mesmo_pet_e_categoria_aceitas_ao_mesmo_tempo_geram_um_so_contrato(): void
    {
        $proposals = array_map(fn () => app(ProposalService::class)->create(['customer_id' => $this->customer->id, 'pet_id' => $this->pet->id,
            'product_version_id' => $this->version->id, 'originating_franchise_id' => $this->unit->id, 'channel' => 'site'], $this->client), [1, 2]);
        $tasks = array_map(fn ($p) => ['op' => 'accept', 'proposal_id' => $p->id, 'user_id' => $this->client->id], $proposals);

        $results = $this->race($tasks, fn () => Customer::whereKey($this->customer->id)->lockForUpdate()->first(), 'for update', 2);

        $this->assertSame(1, Subscription::count(), json_encode($results));
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(['PET_CATEGORY_OCCUPIED'], array_values(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'code')), json_encode($results));
    }

    public function test_copias_da_notificacao_chegando_enquanto_a_primeira_e_gravada_ficam_num_registro_so(): void
    {
        config(['integrations.vindi.enabled' => true, 'integrations.vindi.webhook_user' => self::WEBHOOK_USER, 'integrations.vindi.webhook_password' => self::WEBHOOK_PASSWORD]);
        Queue::fake();
        $body = json_encode(['event' => ['id' => 'evt-paralelo', 'type' => 'bill_paid', 'created_at' => now()->toIso8601String(), 'data' => ['bill' => ['id' => 123]]]]);
        $task = ['op' => 'webhook', 'body' => $body, 'credentials' => self::WEBHOOK_USER.':'.self::WEBHOOK_PASSWORD];
        $first = null;

        // A primeira entrega fica gravada e ainda não confirmada enquanto as cópias chegam.
        $results = $this->race(array_fill(0, 3, $task), function () use ($body, &$first) {
            $first = app(VindiWebhookService::class)->receive(Request::create('/webhooks/vindi', 'POST', [], [], [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Basic '.base64_encode(self::WEBHOOK_USER.':'.self::WEBHOOK_PASSWORD)], $body));
        }, 'insert into `integration_events`', 3);

        $this->assertSame(1, IntegrationEvent::count(), json_encode($results));
        $this->assertSame(array_fill(0, 3, ['ok' => true, 'event_id' => $first->id]), $results);
    }

    /** @return array<string, mixed> */
    private function reserveTask(Subscription $sub, string $key, ?int $franchiseId = null): array
    {
        return ['op' => 'reserve', 'subscription_id' => $sub->id, 'rule_id' => $this->rule->id, 'user_id' => $this->admin->id, 'franchise_id' => $franchiseId ?? $this->unit->id, 'key' => $key];
    }

    /**
     * Dispara as tarefas em processos separados e devolve o resultado de cada uma, na ordem. Numa transação
     * deste processo, $hold segura a linha disputada; ela só é confirmada quando $waiters filhos estiverem
     * parados num comando que contém $waitingFor.
     *
     * @param  list<array<string, mixed>>  $tasks
     * @return list<array<string, mixed>>
     */
    private function race(array $tasks, callable $hold, string $waitingFor, int $waiters): array
    {
        $barrier = sys_get_temp_dir().'/largada-'.uniqid();
        $env = array_merge(getenv(), ['VINDI_ENABLED' => 'true', 'VINDI_WEBHOOK_USER' => self::WEBHOOK_USER, 'VINDI_WEBHOOK_PASSWORD' => self::WEBHOOK_PASSWORD,
            'FINANCIAL_MAX_AGE_MINUTES' => '1440', 'BENEFIT_RESERVATION_MINUTES' => '60']);
        $procs = [];
        foreach ($tasks as $task) {
            $proc = proc_open([PHP_BINARY, base_path('tests/Concurrency/worker.php'), json_encode($task + ['barrier' => $barrier])],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $procs[] = [$proc, $pipes];
        }
        foreach ($procs as [, $pipes]) {
            $line = fgets($pipes[1]);
            if (trim((string) $line) !== 'ready') {
                $this->fail('Processo filho não subiu: '.$line.stream_get_contents($pipes[2]));
            }
        }

        DB::beginTransaction();
        $hold();
        touch($barrier);
        $blocked = $this->waitForWaiters($waitingFor, $waiters);
        DB::commit();

        $results = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            proc_close($proc);
            $results[] = json_decode(trim($out), true) ?? ['ok' => false, 'error' => 'sem resposta', 'message' => $out.$err];
        }
        @unlink($barrier);
        $this->assertSame($waiters, $blocked, 'Nem todos os processos chegaram a esperar a linha disputada: '.json_encode($results));

        return $results;
    }

    /** Espera (até 15 s) os filhos ficarem parados no comando; devolve quantos ficaram. */
    private function waitForWaiters(string $waitingFor, int $count): int
    {
        $until = microtime(true) + 15;
        do {
            $waiting = (int) DB::selectOne('select count(*) as n from information_schema.processlist where id <> connection_id() and info like ?', ['%'.$waitingFor.'%'])->n;
            if ($waiting >= $count) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $until);

        return $waiting;
    }
}

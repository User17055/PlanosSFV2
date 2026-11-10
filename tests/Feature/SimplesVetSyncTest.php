<?php

namespace Tests\Feature;

use App\Jobs\SyncSimplesVetJob;
use App\Models\{Customer,Franchise,IntegrationEvent,Pet,Product,Proposal,Subscription,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** O robô do SimplesVet recebe a tarefa certa e a saúde guarda o resultado ou deixa a pendência para a recepção. */
class SimplesVetSyncTest extends TestCase
{
    use RefreshDatabase;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.simplesvet' => ['enabled' => true, 'user' => 'robo@example.test', 'password' => 'segredo', 'node' => 'node', 'browsers' => '/opt/ms-playwright', 'timeout' => 200]]);
        $unit = Franchise::create(['name' => 'Unidade', 'code' => 'TEST']);
        $customer = Customer::create(['name' => 'Tutora Teste', 'document' => '52998224725', 'email' => 'tutora@example.test', 'phone' => '(18) 99999-0000', 'sex' => 'female',
            'address' => ['zip' => '19063-140', 'street' => 'Rua A', 'number' => '15', 'neighborhood' => 'Jardim Itaipu', 'city' => 'Presidente Prudente', 'state' => 'SP'],
            'acquisition_profile' => ['acquisition_source' => 'shared_link']]);
        $user = User::factory()->create(['role' => 'customer', 'customer_id' => $customer->id, 'active' => true]);
        $pet = Pet::create(['customer_id' => $customer->id, 'name' => 'Maria Lua', 'species' => 'cat', 'sex' => 'female', 'neutered' => true, 'birth_date' => '2022-10-04', 'breed' => 'Mestiço', 'color' => 'Tigrado']);
        $product = Product::create(['name' => 'Econômico Felino', 'code' => 'FEL', 'category' => 'health_plan', 'active' => true]);
        $version = $product->versions()->create(['version' => 1, 'name' => 'Plano Econômico Felino', 'price_cents' => 2400, 'enrollment_fee_cents' => 0, 'billing_cycle' => 'monthly',
            'duration_months' => 12, 'renewal_policy' => 'automatic', 'species' => ['cat'], 'contract_template' => 'x', 'status' => 'published', 'is_demo' => false, 'published_at' => now()]);
        $snapshot = ['customer' => $customer->toArray(), 'pet' => $pet->toArray(), 'version' => $version->toArray()];
        $proposal = Proposal::create(['number' => 'PR-SV', 'customer_id' => $customer->id, 'pet_id' => $pet->id, 'product_version_id' => $version->id, 'originating_franchise_id' => $unit->id,
            'created_by' => $user->id, 'category' => 'health_plan', 'channel' => 'site', 'price_cents' => 2400, 'snapshot' => $snapshot, 'expires_at' => now()->addDay(), 'locked_at' => now(), 'accepted_at' => now()]);
        $this->subscription = Subscription::create(['number' => 'SF-SV', 'proposal_id' => $proposal->id, 'customer_id' => $customer->id, 'pet_id' => $pet->id, 'product_version_id' => $version->id,
            'originating_franchise_id' => $unit->id, 'category' => 'health_plan', 'price_cents' => 2400, 'contract_snapshot' => $snapshot, 'locked_at' => now(),
            'document_status' => 'valid', 'status' => 'active', 'activated_at' => '2026-11-10 13:00:00']);
    }

    private function robot(array $result): void
    {
        Process::fake(['*' => Process::result(json_encode($result))]);
    }

    private function sentTask(): array
    {
        $task = null;
        Process::assertRan(function (PendingProcess $process) use (&$task) { $task = json_decode($process->input, true); return true; });
        return $task;
    }

    public function test_active_plan_marks_tutor_and_pet_and_keeps_the_simplesvet_records(): void
    {
        $this->robot(['ok' => true, 'tutor_id' => '157786', 'pet_id' => '72963', 'actions' => ['tutor: põe CLIENTE PLANO']]);
        SyncSimplesVetJob::dispatchSync($this->subscription->id);
        $task = $this->sentTask();
        $this->assertSame(['activate', false, 'CLIENTE PLANO', 'PLANO ECONÔMICO FELINO DESDE 10/11/2026', 'PLANO ECONÔMICO FELINO DESDE', false],
            [$task['action'], $task['dry_run'], $task['tutor_tag'], $task['pet_tag'], $task['pet_tag_prefix'], $task['keep_tutor_tag']]);
        $this->assertSame(['Google', 'female', '19063-140'], [$task['tutor']['origin'], $task['tutor']['sex'], $task['tutor']['address']['zip']]);
        $this->assertSame(['cat', 'female', true], [$task['pet']['species'], $task['pet']['sex'], $task['pet']['neutered']]);
        $this->assertSame(['157786', '72963'], [$this->subscription->customer->fresh()->simplesvet_id, $this->subscription->pet->fresh()->simplesvet_id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'simplesvet.synced', 'entity_id' => $this->subscription->id]);
        Process::assertRan(fn (PendingProcess $process) => $process->environment['SIMPLESVET_PASSWORD'] === 'segredo' && ! str_contains((string) $process->input, 'segredo'));
    }

    public function test_what_needs_a_person_becomes_a_task_for_the_reception_and_is_cleared_when_it_works(): void
    {
        $this->robot(['ok' => false, 'manual' => true, 'error' => 'Mais de um animal parecido com "Maria Lua" no SimplesVet: LUA, MARIA']);
        SyncSimplesVetJob::dispatchSync($this->subscription->id);
        $event = IntegrationEvent::where('provider', 'simplesvet')->sole();
        $this->assertSame('manual', $event->status);
        $this->assertStringContainsString('Mais de um animal parecido', $event->error);
        $this->assertNull($this->subscription->pet->fresh()->simplesvet_id);
        // Resolvido pela recepção, a próxima rodada fecha a pendência.
        $this->robot(['ok' => true, 'tutor_id' => '157786', 'pet_id' => '72963', 'actions' => []]);
        SyncSimplesVetJob::dispatchSync($this->subscription->id);
        $this->assertSame('synced', $event->fresh()->status);
    }

    public function test_ended_plan_removes_marks_but_keeps_the_tutor_one_while_another_plan_is_active(): void
    {
        $this->subscription->update(['status' => 'ended']);
        $proposal = $this->subscription->proposal->replicate(['number']);
        $proposal->forceFill(['number' => 'PR-SV2'])->saveQuietly();
        $other = $this->subscription->replicate(['number', 'proposal_id']);
        $other->forceFill(['number' => 'SF-SV2', 'status' => 'active', 'proposal_id' => $proposal->id])->saveQuietly();
        $this->robot(['ok' => true, 'tutor_id' => '157786', 'pet_id' => '72963', 'actions' => []]);
        SyncSimplesVetJob::dispatchSync($this->subscription->id);
        $task = $this->sentTask();
        $this->assertSame(['deactivate', true], [$task['action'], $task['keep_tutor_tag']]);
    }

    public function test_robot_off_or_contract_not_active_does_not_run_anything(): void
    {
        Process::fake();
        $this->subscription->update(['status' => 'awaiting_regularization']);
        SyncSimplesVetJob::dispatchSync($this->subscription->id);
        Process::assertNothingRan();
        config(['integrations.simplesvet.enabled' => false]);
        $this->subscription->update(['status' => 'active']);
        SyncSimplesVetJob::dispatchSync($this->subscription->id);
        Process::assertNothingRan();
        $this->assertStringContainsString('robô está desligado', IntegrationEvent::where('provider', 'simplesvet')->sole()->error);
    }
}

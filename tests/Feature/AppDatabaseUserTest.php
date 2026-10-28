<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BenefitUsage;
use App\Services\Domain\BenefitService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsActiveSubscription;
use Tests\TestCase;

/**
 * Permissões do usuário da aplicação (deploy/app-grants.sh). Só roda no passo da CI que conecta como esse
 * usuário (PLANOS_CHECK_APP_GRANTS=1), com o banco já migrado pela credencial de migração.
 */
class AppDatabaseUserTest extends TestCase
{
    use BuildsActiveSubscription, DatabaseTransactions;

    /** Código do MySQL para comando negado por falta de permissão. */
    protected const DENIED = 1142;

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('PLANOS_CHECK_APP_GRANTS')) {
            $this->markTestSkipped('Só no passo da CI que conecta como o usuário da aplicação.');
        }
        $this->setUpScenario();
    }

    public function test_contratacao_consumo_e_estorno_funcionam_com_o_usuario_da_aplicacao(): void
    {
        $subscription = $this->activeSubscription();
        $service = app(BenefitService::class);
        $usage = $service->reserve($subscription, $this->rule, $this->admin, $this->unit->id, 1, 'app-user');
        $service->consume($usage, $this->admin);

        $reversal = $service->reverse($usage->fresh(), $this->admin, 1, 'teste de estorno');

        $this->assertSame('active', $subscription->status);
        $this->assertSame('reversal', $reversal->status);
        $this->assertSame(2, BenefitUsage::where('subscription_id', $subscription->id)->count());
        $this->assertTrue(AuditLog::where('action', 'benefit_reversed')->exists());
    }

    public function test_auditoria_recusa_alterar_e_excluir_pela_permissao(): void
    {
        $this->assertDenied(fn () => DB::table('audit_logs')->where('id', 0)->update(['action' => 'x']), 'UPDATE em audit_logs');
        $this->assertDenied(fn () => DB::table('audit_logs')->where('id', 0)->delete(), 'DELETE em audit_logs');

        $this->assertSame(0, DB::table('proposals')->where('id', 0)->update(['status' => 'draft']));
    }

    // Por último: no MySQL, DDL pode encerrar a transação do teste (commit implícito) mesmo negado.
    public function test_usuario_da_aplicacao_nao_altera_a_estrutura_nem_remove_as_travas(): void
    {
        $this->assertDenied(fn () => DB::statement('CREATE TABLE zz_app_grants (id INT)'), 'CREATE TABLE');
        $this->assertDenied(fn () => DB::statement('ALTER TABLE subscriptions ADD COLUMN zz_app_grants INT'), 'ALTER TABLE');
        $this->assertDenied(fn () => DB::unprepared('DROP TRIGGER subscriptions_immutable_update'), 'DROP TRIGGER');
    }

    protected function assertDenied(callable $statement, string $what): void
    {
        try {
            $statement();
        } catch (QueryException $e) {
            $this->assertSame(self::DENIED, $e->errorInfo[1] ?? null, "{$what}: falhou por outro motivo: {$e->getMessage()}");

            return;
        }
        $this->fail("{$what} deveria ser negado ao usuário da aplicação.");
    }
}

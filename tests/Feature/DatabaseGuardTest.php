<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ContractDocument;
use App\Services\Domain\BenefitService;
use App\Services\Domain\CancellationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsActiveSubscription;
use Tests\TestCase;

/**
 * Each database guard answers with its own code when bypassed with direct SQL, in SQLite and in MySQL (CI job).
 * A generic QueryException is not enough: a foreign key or a syntax error would also raise one.
 */
class DatabaseGuardTest extends TestCase
{
    use BuildsActiveSubscription, RefreshDatabase;

    private const MYSQL_TRIGGERS = [
        'attribution_touches_append_only_delete', 'attribution_touches_append_only_update', 'audit_logs_append_only', 'audit_logs_preserve_delete',
        'benefit_rules_published_delete', 'benefit_rules_published_insert', 'benefit_rules_published_update', 'benefit_usages_append_only',
        'benefit_usages_preserve_delete', 'contract_documents_immutable_update', 'contract_documents_preserve_delete', 'marketing_links_identity_immutable',
        'product_versions_immutable_update', 'product_versions_layout_immutable', 'product_versions_preserve_delete', 'products_immutable_update',
        'proposals_attribution_immutable', 'proposals_immutable_update', 'proposals_preserve_delete', 'subscriptions_activation_clock',
        'subscriptions_attribution_immutable', 'subscriptions_immutable_update', 'subscriptions_preserve_delete', 'subscriptions_terminal',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    public function test_mysql_has_every_contractual_trigger(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Inventário dos triggers do MySQL; o do SQLite está em DomainRulesTest.');
        }
        $names = array_column(DB::select('select trigger_name as name from information_schema.triggers where trigger_schema = ?', [DB::getDatabaseName()]), 'name');
        $expected = self::MYSQL_TRIGGERS;
        sort($names);
        sort($expected);
        $this->assertSame($expected, $names);
    }

    public function test_each_guard_rejects_direct_sql_with_its_own_code_and_allows_operational_fields(): void
    {
        $s = $this->activeSubscription();
        $service = app(BenefitService::class);
        $usage = $service->consume($service->reserve($s, $this->rule, $this->admin, $this->unit->id, 1, 'guard'), $this->admin);
        $document = ContractDocument::where('subscription_id', $s->id)->firstOrFail();
        $audit = AuditLog::firstOrFail();
        $link = DB::table('marketing_links')->insertGetId(['name' => 'Link de teste', 'token_hash' => str_repeat('a', 64), 'token_encrypted' => 'x',
            'owner_id' => $this->admin->id, 'created_by' => $this->admin->id, 'originating_franchise_id' => $this->unit->id, 'created_at' => now(), 'updated_at' => now()]);
        $touch = DB::table('attribution_touches')->insertGetId(['session_key_hash' => str_repeat('b', 64), 'acquisition_source' => 'direct',
            'touched_at' => now(), 'metadata_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $table = fn (string $name, int $id) => DB::table($name)->where('id', $id);

        $this->assertGuard('CONTRACT_IMMUTABLE', fn () => $table('subscriptions', $s->id)->update(['price_cents' => 1]));
        $this->assertGuard('ACTIVATION_CLOCK_IMMUTABLE', fn () => $table('subscriptions', $s->id)->update(['effective_start_at' => now()->subYear()]));
        $this->assertGuard('ATTRIBUTION_IMMUTABLE', fn () => $table('subscriptions', $s->id)->update(['acquisition_source' => 'instagram']));
        $this->assertGuard('HISTORY_PRESERVED', fn () => $table('subscriptions', $s->id)->delete());
        $this->assertGuard('CONTRACT_IMMUTABLE', fn () => $table('proposals', $s->proposal_id)->update(['price_cents' => 1]));
        $this->assertGuard('ATTRIBUTION_IMMUTABLE', fn () => $table('proposals', $s->proposal_id)->update(['referrer_name' => 'Outra pessoa']));
        $this->assertGuard('HISTORY_PRESERVED', fn () => $table('proposals', $s->proposal_id)->delete());
        $this->assertGuard('CONTRACT_IMMUTABLE', fn () => $table('products', $this->version->product_id)->update(['category' => 'package']));
        $this->assertGuard('CONTRACT_IMMUTABLE', fn () => $table('product_versions', $this->version->id)->update(['price_cents' => 1]));
        $this->assertGuard('CONTRACT_IMMUTABLE', fn () => $table('product_versions', $this->version->id)->update(['contract_layout' => '{"paragraphs":[]}']));
        $this->assertGuard('HISTORY_PRESERVED', fn () => $table('product_versions', $this->version->id)->delete());
        $this->assertGuard('PUBLISHED_VERSION_IMMUTABLE', fn () => $table('benefit_rules', $this->rule->id)->update(['quantity_limit' => 99]));
        $this->assertGuard('PUBLISHED_VERSION_IMMUTABLE', fn () => $table('benefit_rules', $this->rule->id)->delete());
        $this->assertGuard('PUBLISHED_VERSION_IMMUTABLE', fn () => DB::table('benefit_rules')->insert(['product_version_id' => $this->version->id, 'name' => 'Extra', 'code' => 'EXTRA',
            'waiting_days' => 0, 'period_type' => 'contract_year', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertGuard('LEDGER_IMMUTABLE', fn () => $table('benefit_usages', $usage->id)->update(['quantity' => 0]));
        $this->assertGuard('HISTORY_PRESERVED', fn () => $table('benefit_usages', $usage->id)->delete());
        $this->assertGuard('CONTRACT_IMMUTABLE', fn () => $table('contract_documents', $document->id)->update(['sha256' => str_repeat('0', 64)]));
        $this->assertGuard('HISTORY_PRESERVED', fn () => $table('contract_documents', $document->id)->delete());
        $this->assertGuard('LEDGER_IMMUTABLE', fn () => $table('audit_logs', $audit->id)->update(['action' => 'alterado']));
        $this->assertGuard('HISTORY_PRESERVED', fn () => $table('audit_logs', $audit->id)->delete());
        $this->assertGuard('MARKETING_LINK_IMMUTABLE', fn () => $table('marketing_links', $link)->update(['name' => 'Outro nome']));
        $this->assertGuard('ATTRIBUTION_TOUCH_APPEND_ONLY', fn () => $table('attribution_touches', $touch)->update(['acquisition_source' => 'instagram']));
        $this->assertGuard('ATTRIBUTION_TOUCH_APPEND_ONLY', fn () => $table('attribution_touches', $touch)->delete());

        // Control: the same rows still accept the fields the operation changes, so the guards are not blocking everything.
        $this->assertSame(1, $table('subscriptions', $s->id)->update(['financial_checked_at' => now()->addMinute()]));
        $this->assertSame(1, $table('marketing_links', $link)->update(['status' => 'inactive']));
        $this->assertSame(1, $table('contract_documents', $document->id)->update(['customer_notified_at' => now()]));

        app(CancellationService::class)->cancel($s->fresh(), $this->admin, 'Encerramento de teste');
        $this->assertGuard('TERMINAL_SUBSCRIPTION', fn () => $table('subscriptions', $s->id)->update(['status' => 'active']));
    }

    private function assertGuard(string $code, callable $statement): void
    {
        try {
            $statement();
        } catch (QueryException $e) {
            $this->assertStringContainsString($code, $e->getMessage());

            return;
        }
        $this->fail("O banco aceitou o comando que a trava {$code} deveria recusar.");
    }
}

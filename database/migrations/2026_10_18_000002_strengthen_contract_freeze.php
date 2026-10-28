<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->install(true);
    }

    public function down(): void
    {
        // Rollback keeps the original contract-binding safeguards in place.
        $this->install(false);
    }

    private function install(bool $strong): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        if (! $sqlite && DB::getDriverName() !== 'mysql') {
            return;
        }
        $guards = [
            'subscriptions' => ['proposal_id', 'customer_id', 'pet_id', 'product_version_id', 'category', 'locked_at', 'contract_snapshot', 'price_cents', 'originating_franchise_id'],
            'proposals' => ['customer_id', 'pet_id', 'product_version_id', 'category', 'locked_at', 'snapshot', 'price_cents', 'originating_franchise_id', 'signature_method'],
        ];
        if ($strong) {
            $guards['subscriptions'][] = 'requested_start_at';
            $guards['proposals'] = array_merge($guards['proposals'], ['requested_start_at', 'accepted_at', 'acceptance_evidence']);
        }
        foreach ($guards as $table => $fields) {
            $name = $table.'_immutable_update';
            DB::unprepared("DROP TRIGGER IF EXISTS $name");
            $changes = implode(' OR ', array_map(fn ($f) => $sqlite ? "OLD.$f IS NOT NEW.$f" : "NOT (OLD.$f <=> NEW.$f)", $fields));
            if ($sqlite) {
                DB::unprepared("CREATE TRIGGER $name BEFORE UPDATE ON $table WHEN OLD.locked_at IS NOT NULL AND ($changes) BEGIN SELECT RAISE(ABORT, 'CONTRACT_IMMUTABLE'); END");
            } else {
                DB::unprepared("CREATE TRIGGER $name BEFORE UPDATE ON $table FOR EACH ROW BEGIN IF OLD.locked_at IS NOT NULL AND ($changes) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CONTRACT_IMMUTABLE'; END IF; END");
            }
        }
    }
};

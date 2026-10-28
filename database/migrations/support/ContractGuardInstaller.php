<?php

namespace Database\MigrationSupport;

use Illuminate\Support\Facades\DB;

/** SQLite table rebuilds discard attached triggers. Reinstall them after every such schema change. */
final class ContractGuardInstaller
{
    public function restore(bool $includeAttribution = true): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        if (! $sqlite && DB::getDriverName() !== 'mysql') {
            return;
        }
        $bindings = [
            'proposals' => ['customer_id', 'pet_id', 'product_version_id', 'category', 'locked_at', 'snapshot', 'price_cents', 'originating_franchise_id', 'signature_method', 'requested_start_at', 'accepted_at', 'acceptance_evidence'],
            'subscriptions' => ['proposal_id', 'customer_id', 'pet_id', 'product_version_id', 'category', 'locked_at', 'contract_snapshot', 'price_cents', 'originating_franchise_id', 'requested_start_at'],
        ];
        foreach ($bindings as $table => $fields) {
            $this->install($table.'_immutable_update', $table, 'UPDATE', 'OLD.locked_at IS NOT NULL AND ('.$this->changed($fields, $sqlite).')', 'CONTRACT_IMMUTABLE', $sqlite);
            $this->install($table.'_preserve_delete', $table, 'DELETE', 'OLD.locked_at IS NOT NULL', 'HISTORY_PRESERVED', $sqlite);
        }
        $this->install('subscriptions_terminal', 'subscriptions', 'UPDATE', "OLD.status IN ('cancelled','ended') AND NEW.status <> OLD.status", 'TERMINAL_SUBSCRIPTION', $sqlite);
        $this->install('subscriptions_activation_clock', 'subscriptions', 'UPDATE', 'OLD.activated_at IS NOT NULL AND ('.$this->changed(['activated_at', 'effective_start_at'], $sqlite).')', 'ACTIVATION_CLOCK_IMMUTABLE', $sqlite);
        if ($includeAttribution) {
            $fields = ['acquisition_source', 'marketing_link_id', 'link_owner_id', 'referrer_customer_id', 'referrer_name', 'campaign_name', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'first_touch_id', 'last_touch_id', 'first_touch_at', 'last_touch_at', 'attribution_snapshot'];
            foreach (['proposals', 'subscriptions'] as $table) {
                $this->install($table.'_attribution_immutable', $table, 'UPDATE', 'OLD.locked_at IS NOT NULL AND ('.$this->changed($fields, $sqlite).')', 'ATTRIBUTION_IMMUTABLE', $sqlite);
            }
        }
    }

    private function changed(array $fields, bool $sqlite): string
    {
        return implode(' OR ', array_map(fn ($field) => $sqlite ? "OLD.$field IS NOT NEW.$field" : "NOT (OLD.$field <=> NEW.$field)", $fields));
    }

    private function install(string $name, string $table, string $operation, string $condition, string $message, bool $sqlite): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        if ($sqlite) {
            DB::unprepared("CREATE TRIGGER $name BEFORE $operation ON $table WHEN $condition BEGIN SELECT RAISE(ABORT, '$message'); END");
        } else {
            DB::unprepared("CREATE TRIGGER $name BEFORE $operation ON $table FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$message'; END IF; END");
        }
    }
}

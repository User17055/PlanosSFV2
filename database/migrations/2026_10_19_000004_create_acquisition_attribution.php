<?php

use Database\MigrationSupport\ContractGuardInstaller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/support/ContractGuardInstaller.php';

return new class extends Migration
{
    private array $attributionFields = ['acquisition_source', 'marketing_link_id', 'link_owner_id', 'referrer_customer_id', 'referrer_name', 'campaign_name', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'first_touch_id', 'last_touch_id', 'first_touch_at', 'last_touch_at', 'attribution_snapshot'];

    public function up(): void
    {
        Schema::create('marketing_links', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->char('token_hash', 64)->unique();
            $t->text('token_encrypted');
            $t->string('acquisition_source', 24)->default('shared_link');
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('originating_franchise_id')->constrained('franchises')->restrictOnDelete();
            $t->foreignId('product_version_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('referrer_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $t->string('referrer_name', 180)->nullable();
            $t->string('campaign_name', 120)->nullable();
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $field) {
                $t->string($field, 120)->nullable();
            }
            $t->string('status', 16)->default('active');
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->index(['originating_franchise_id', 'status']);
            $t->index(['owner_id', 'created_at']);
        });
        Schema::create('attribution_touches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('marketing_link_id')->nullable()->constrained()->restrictOnDelete();
            $t->char('session_key_hash', 64);
            $t->string('acquisition_source', 24);
            $t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('originating_franchise_id')->nullable()->constrained('franchises')->restrictOnDelete();
            $t->foreignId('product_version_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('referrer_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $t->string('referrer_name', 180)->nullable();
            $t->string('campaign_name', 120)->nullable();
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $field) {
                $t->string($field, 120)->nullable();
            }
            $t->string('referrer_host', 253)->nullable();
            $t->timestamp('touched_at');
            $t->json('metadata_snapshot');
            $t->timestamps();
            $t->index(['marketing_link_id', 'touched_at']);
            $t->index(['originating_franchise_id', 'touched_at']);
            $t->index(['session_key_hash', 'touched_at']);
            $t->index(['acquisition_source', 'touched_at']);
        });
        foreach (['proposals', 'subscriptions'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                // Unknown means historical origin was not recorded; no inference from the hiring channel.
                $t->string('acquisition_source', 24)->default('unknown');
                $t->foreignId('marketing_link_id')->nullable()->constrained()->restrictOnDelete();
                $t->foreignId('link_owner_id')->nullable()->constrained('users')->restrictOnDelete();
                $t->foreignId('referrer_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
                $t->string('referrer_name', 180)->nullable();
                $t->string('campaign_name', 120)->nullable();
                foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $field) {
                    $t->string($field, 120)->nullable();
                }
                $t->foreignId('first_touch_id')->nullable()->constrained('attribution_touches')->restrictOnDelete();
                $t->foreignId('last_touch_id')->nullable()->constrained('attribution_touches')->restrictOnDelete();
                $t->timestamp('first_touch_at')->nullable();
                $t->timestamp('last_touch_at')->nullable();
                $t->json('attribution_snapshot')->nullable();
                $t->index(['acquisition_source', 'created_at']);
                $t->index(['marketing_link_id', 'created_at']);
                $t->index(['link_owner_id', 'created_at']);
                $t->index(['referrer_customer_id', 'created_at']);
            });
        }
        $this->guards();
        // This migration must remain safe even if a later repair migration has not run yet.
        (new ContractGuardInstaller)->restore();
    }

    private function guards(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        if (! $sqlite && DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach (['proposals', 'subscriptions'] as $table) {
            $changes = implode(' OR ', array_map(fn ($f) => $sqlite ? "OLD.$f IS NOT NEW.$f" : "NOT (OLD.$f <=> NEW.$f)", $this->attributionFields));
            $name = $table.'_attribution_immutable';
            if ($sqlite) {
                DB::unprepared("CREATE TRIGGER $name BEFORE UPDATE ON $table WHEN OLD.locked_at IS NOT NULL AND ($changes) BEGIN SELECT RAISE(ABORT, 'ATTRIBUTION_IMMUTABLE'); END");
            } else {
                DB::unprepared("CREATE TRIGGER $name BEFORE UPDATE ON $table FOR EACH ROW BEGIN IF OLD.locked_at IS NOT NULL AND ($changes) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ATTRIBUTION_IMMUTABLE'; END IF; END");
            }
        }
        foreach (['UPDATE', 'DELETE'] as $op) {
            $name = 'attribution_touches_append_only_'.strtolower($op);
            if ($sqlite) {
                DB::unprepared("CREATE TRIGGER $name BEFORE $op ON attribution_touches BEGIN SELECT RAISE(ABORT, 'ATTRIBUTION_TOUCH_APPEND_ONLY'); END");
            } else {
                DB::unprepared("CREATE TRIGGER $name BEFORE $op ON attribution_touches FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ATTRIBUTION_TOUCH_APPEND_ONLY'; END");
            }
        }
        $fields = ['token_hash', 'token_encrypted', 'name', 'acquisition_source', 'owner_id', 'created_by', 'originating_franchise_id', 'product_version_id', 'referrer_customer_id', 'referrer_name', 'campaign_name', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
        $changes = implode(' OR ', array_map(fn ($f) => $sqlite ? "OLD.$f IS NOT NEW.$f" : "NOT (OLD.$f <=> NEW.$f)", $fields));
        if ($sqlite) {
            DB::unprepared("CREATE TRIGGER marketing_links_identity_immutable BEFORE UPDATE ON marketing_links WHEN $changes BEGIN SELECT RAISE(ABORT, 'MARKETING_LINK_IMMUTABLE'); END");
        } else {
            DB::unprepared("CREATE TRIGGER marketing_links_identity_immutable BEFORE UPDATE ON marketing_links FOR EACH ROW BEGIN IF $changes THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MARKETING_LINK_IMMUTABLE'; END IF; END");
        }
    }

    public function down(): void
    {
        foreach (['proposals_attribution_immutable', 'subscriptions_attribution_immutable', 'attribution_touches_append_only_update', 'attribution_touches_append_only_delete', 'marketing_links_identity_immutable'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        foreach (['subscriptions', 'proposals'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                // MySQL may reuse the composite reporting index for the FK; remove constraints first.
                foreach (['marketing_link_id', 'link_owner_id', 'referrer_customer_id', 'first_touch_id', 'last_touch_id'] as $field) {
                    $t->dropForeign([$field]);
                }
                foreach (['acquisition_source', 'marketing_link_id', 'link_owner_id', 'referrer_customer_id'] as $field) {
                    $t->dropIndex([$field, 'created_at']);
                }
                $t->dropColumn($this->attributionFields);
            });
        }
        Schema::dropIfExists('attribution_touches');
        Schema::dropIfExists('marketing_links');
        // Removing the new foreign keys also rebuilds these tables on SQLite.
        (new ContractGuardInstaller)->restore(false);
    }
};

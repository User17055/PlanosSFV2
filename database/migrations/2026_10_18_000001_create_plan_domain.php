<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franchises', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code')->unique();
            $t->boolean('active')->default(true);
            $t->string('city')->nullable();
            $t->string('state', 2)->nullable();
            $t->string('address')->nullable();
            $t->timestamps();
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('document', 24)->nullable()->unique();
            $t->foreignId('originating_franchise_id')->nullable()->constrained('franchises')->restrictOnDelete();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->json('address')->nullable();
            $t->timestamp('contact_verified_at')->nullable();
            $t->string('financial_verification_status')->default('pending');
            $t->timestamp('financial_checked_at')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('operator');
            $t->foreignId('franchise_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('active')->default(true);
        });
        Schema::create('user_franchises', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('franchise_id')->constrained()->restrictOnDelete();
            $t->json('permissions')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['user_id', 'franchise_id']);
        });
        Schema::create('pets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('species');
            $t->string('breed')->nullable();
            $t->string('sex')->nullable();
            $t->date('birth_date')->nullable();
            $t->unsignedInteger('estimated_age_months')->nullable();
            $t->string('microchip')->nullable()->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code')->unique();
            $t->string('category');
            $t->string('description')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('product_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('name');
            $t->text('description')->nullable();
            $t->unsignedBigInteger('price_cents');
            $t->unsignedBigInteger('enrollment_fee_cents')->default(0);
            $t->string('billing_cycle');
            $t->unsignedInteger('duration_months')->nullable();
            $t->string('renewal_policy')->default('none');
            $t->json('species');
            $t->unsignedInteger('min_age_months')->nullable();
            $t->unsignedInteger('max_age_months')->nullable();
            $t->json('allowed_franchise_ids')->nullable();
            $t->longText('contract_template');
            $t->string('status')->default('draft');
            $t->boolean('is_demo')->default(false);
            $t->timestamp('published_at')->nullable();
            $t->timestamp('offer_starts_at')->nullable();
            $t->timestamp('offer_ends_at')->nullable();
            $t->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['product_id', 'version']);
        });
        Schema::create('benefit_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_version_id')->constrained()->restrictOnDelete();
            $t->string('code');
            $t->string('name');
            $t->text('description')->nullable();
            $t->unsignedInteger('waiting_days')->default(0);
            $t->unsignedInteger('quantity_limit')->nullable();
            $t->string('unit')->default('procedimento');
            $t->string('period_type');
            $t->unsignedInteger('renewal_window_days')->nullable();
            $t->string('coverage_type')->default('included');
            $t->unsignedInteger('discount_basis_points')->nullable();
            $t->unsignedBigInteger('copay_cents')->default(0);
            $t->boolean('requires_authorization')->default(false);
            $t->json('allowed_franchise_ids')->nullable();
            $t->text('exclusions')->nullable();
            $t->timestamps();
            $t->unique(['product_version_id', 'code']);
        });
        Schema::create('proposals', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('pet_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_version_id')->constrained()->restrictOnDelete();
            $t->foreignId('originating_franchise_id')->constrained('franchises')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('category');
            $t->string('channel');
            $t->string('prefill_by')->default('seller');
            $t->string('signature_method')->default('digital');
            $t->string('status')->default('awaiting_acceptance');
            $t->unsignedBigInteger('price_cents');
            $t->json('snapshot');
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->timestamp('requested_start_at')->nullable();
            $t->char('link_token_hash', 64)->nullable()->unique();
            $t->timestamp('link_expires_at')->nullable();
            $t->timestamp('link_revoked_at')->nullable();
            $t->json('acceptance_evidence')->nullable();
            $t->timestamps();
            $t->index(['originating_franchise_id', 'status']);
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('proposal_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('pet_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_version_id')->constrained()->restrictOnDelete();
            $t->foreignId('originating_franchise_id')->constrained('franchises')->restrictOnDelete();
            $t->foreignId('previous_subscription_id')->nullable()->constrained('subscriptions')->restrictOnDelete();
            $t->string('category');
            $t->string('status')->default('awaiting_regularization');
            $t->string('document_status')->default('pending');
            $t->timestamp('locked_at');
            $t->json('contract_snapshot');
            $t->unsignedBigInteger('price_cents');
            $t->timestamp('requested_start_at')->nullable();
            $t->timestamp('effective_start_at')->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->string('vindi_subscription_id')->nullable();
            $t->string('provider_account')->default('central');
            $t->string('financial_verification_status')->default('pending');
            $t->timestamp('financial_checked_at')->nullable();
            $t->timestamps();
            $t->index(['customer_id', 'status']);
            $t->index(['originating_franchise_id', 'status']);
            $t->unique(['provider_account', 'vindi_subscription_id']);
        });
        Schema::create('contract_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $t->foreignId('proposal_id')->constrained()->restrictOnDelete();
            $t->string('kind')->default('final_contract');
            $t->string('signature_method');
            $t->string('status')->default('pending');
            $t->string('storage_path');
            $t->string('original_name')->nullable();
            $t->string('mime_type')->default('application/pdf');
            $t->char('sha256', 64);
            $t->unsignedBigInteger('size_bytes');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('validated_at')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->json('evidence')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->timestamps();
            $t->unique(['subscription_id', 'version']);
        });
        Schema::create('pet_product_slots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pet_id')->constrained()->restrictOnDelete();
            $t->string('product_category');
            $t->foreignId('proposal_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('state')->default('reserved');
            $t->timestamp('reserved_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->timestamps();
            $t->unique(['pet_id', 'product_category']);
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('originating_franchise_id')->constrained('franchises')->restrictOnDelete();
            $t->string('provider_account')->default('central');
            $t->string('external_id')->nullable();
            $t->timestamp('competence_start')->nullable();
            $t->timestamp('competence_end')->nullable();
            $t->timestamp('due_at');
            $t->unsignedBigInteger('amount_cents');
            $t->unsignedBigInteger('paid_cents')->default(0);
            $t->string('status')->default('pending');
            $t->string('provider_status')->nullable();
            $t->timestamp('provider_updated_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->boolean('is_initial')->default(false);
            $t->text('payment_url')->nullable();
            $t->string('reconciliation_status')->default('pending');
            $t->string('payment_reference')->nullable();
            $t->timestamps();
            $t->unique(['provider_account', 'external_id']);
            $t->index(['customer_id', 'due_at', 'status']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->string('provider_account')->default('central');
            $t->string('external_transaction_id');
            $t->unsignedBigInteger('amount_cents');
            $t->timestamp('paid_at');
            $t->string('source');
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['provider_account', 'external_transaction_id']);
        });
        Schema::create('benefit_usages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $t->foreignId('benefit_rule_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('pet_id')->constrained()->restrictOnDelete();
            $t->foreignId('servicing_franchise_id')->constrained('franchises')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('original_usage_id')->nullable()->constrained('benefit_usages')->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->string('status');
            $t->timestamp('period_start');
            $t->timestamp('period_end')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('consumed_at')->nullable();
            $t->string('idempotency_key')->unique();
            $t->string('service_item_id')->nullable()->unique();
            $t->text('reason')->nullable();
            $t->json('rule_snapshot');
            $t->timestamps();
            $t->index(['subscription_id', 'benefit_rule_id', 'period_start', 'status'], 'usage_balance_idx');
        });
        Schema::create('integration_events', function (Blueprint $t) {
            $t->id();
            $t->string('provider');
            $t->string('provider_account')->default('central');
            $t->string('external_event_id')->nullable();
            $t->string('event_type');
            $t->string('entity_type')->nullable();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('payload')->nullable();
            $t->string('idempotency_key')->unique();
            $t->string('status')->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->text('error')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->index(['provider', 'status']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('franchise_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('action');
            $t->string('entity_type');
            $t->unsignedBigInteger('entity_id');
            $t->json('data')->nullable();
            $t->text('reason')->nullable();
            $t->string('correlation_id')->nullable();
            $t->timestamps();
            $t->index(['entity_type', 'entity_id']);
        });
        $this->protectImmutableRecords();
    }

    private function protectImmutableRecords(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        if (! $sqlite && DB::getDriverName() !== 'mysql') {
            return;
        }
        $guards = [
            'subscriptions' => ['OLD.locked_at IS NOT NULL', ['proposal_id', 'customer_id', 'pet_id', 'product_version_id', 'category', 'locked_at', 'contract_snapshot', 'price_cents', 'originating_franchise_id', 'requested_start_at']],
            'proposals' => ['OLD.locked_at IS NOT NULL', ['customer_id', 'pet_id', 'product_version_id', 'category', 'locked_at', 'snapshot', 'price_cents', 'originating_franchise_id', 'signature_method', 'requested_start_at', 'accepted_at', 'acceptance_evidence']],
            'products' => ['1 = 1', ['category']],
            'product_versions' => ['OLD.published_at IS NOT NULL', ['product_id', 'version', 'name', 'price_cents', 'enrollment_fee_cents', 'billing_cycle', 'duration_months', 'renewal_policy', 'species', 'min_age_months', 'max_age_months', 'allowed_franchise_ids', 'contract_template', 'published_at']],
            'contract_documents' => ['1 = 1', ['subscription_id', 'proposal_id', 'kind', 'signature_method', 'storage_path', 'sha256', 'size_bytes', 'version', 'evidence', 'accepted_at']],
        ];
        foreach ($guards as $table => [$condition,$fields]) {
            $changes = implode(' OR ', array_map(fn ($f) => $sqlite ? "OLD.$f IS NOT NEW.$f" : "NOT (OLD.$f <=> NEW.$f)", $fields));
            $name = $table.'_immutable_update';
            if ($sqlite) {
                DB::unprepared("CREATE TRIGGER $name BEFORE UPDATE ON $table WHEN ($condition) AND ($changes) BEGIN SELECT RAISE(ABORT, 'CONTRACT_IMMUTABLE'); END");
            } else {
                DB::unprepared("CREATE TRIGGER $name BEFORE UPDATE ON $table FOR EACH ROW BEGIN IF ($condition) AND ($changes) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CONTRACT_IMMUTABLE'; END IF; END");
            }
        }
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $op) {
            $ref = $op === 'INSERT' ? 'NEW' : 'OLD';
            $name = 'benefit_rules_published_'.strtolower($op);
            $condition = "EXISTS (SELECT 1 FROM product_versions WHERE id = $ref.product_version_id AND published_at IS NOT NULL)";
            if ($op === 'UPDATE') {
                $condition = "($condition OR EXISTS (SELECT 1 FROM product_versions WHERE id = NEW.product_version_id AND published_at IS NOT NULL))";
            }
            if ($sqlite) {
                DB::unprepared("CREATE TRIGGER $name BEFORE $op ON benefit_rules WHEN $condition BEGIN SELECT RAISE(ABORT, 'PUBLISHED_VERSION_IMMUTABLE'); END");
            } else {
                DB::unprepared("CREATE TRIGGER $name BEFORE $op ON benefit_rules FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PUBLISHED_VERSION_IMMUTABLE'; END IF; END");
            }
        }
        foreach (['subscriptions' => 'OLD.locked_at IS NOT NULL', 'proposals' => 'OLD.locked_at IS NOT NULL', 'contract_documents' => '1 = 1', 'product_versions' => 'OLD.published_at IS NOT NULL', 'benefit_usages' => "OLD.status IN ('consumed', 'reversal')", 'audit_logs' => '1 = 1'] as $table => $condition) {
            $name = $table.'_preserve_delete';
            if ($sqlite) {
                DB::unprepared("CREATE TRIGGER $name BEFORE DELETE ON $table WHEN $condition BEGIN SELECT RAISE(ABORT, 'HISTORY_PRESERVED'); END");
            } else {
                DB::unprepared("CREATE TRIGGER $name BEFORE DELETE ON $table FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HISTORY_PRESERVED'; END IF; END");
            }
        }
        $condition = "OLD.status IN ('cancelled','ended') AND NEW.status <> OLD.status";
        if ($sqlite) {
            DB::unprepared("CREATE TRIGGER subscriptions_terminal BEFORE UPDATE ON subscriptions WHEN $condition BEGIN SELECT RAISE(ABORT, 'TERMINAL_SUBSCRIPTION'); END");
        } else {
            DB::unprepared("CREATE TRIGGER subscriptions_terminal BEFORE UPDATE ON subscriptions FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TERMINAL_SUBSCRIPTION'; END IF; END");
        }
        $condition = 'OLD.activated_at IS NOT NULL AND '.($sqlite ? '(OLD.activated_at IS NOT NEW.activated_at OR OLD.effective_start_at IS NOT NEW.effective_start_at)' : '(NOT (OLD.activated_at <=> NEW.activated_at) OR NOT (OLD.effective_start_at <=> NEW.effective_start_at))');
        if ($sqlite) {
            DB::unprepared("CREATE TRIGGER subscriptions_activation_clock BEFORE UPDATE ON subscriptions WHEN $condition BEGIN SELECT RAISE(ABORT, 'ACTIVATION_CLOCK_IMMUTABLE'); END");
        } else {
            DB::unprepared("CREATE TRIGGER subscriptions_activation_clock BEFORE UPDATE ON subscriptions FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ACTIVATION_CLOCK_IMMUTABLE'; END IF; END");
        }
        foreach (['audit_logs' => '1=1', 'benefit_usages' => "OLD.status IN ('consumed','reversal')"] as $table => $condition) {
            $name = $table.'_append_only';
            if ($sqlite) {
                DB::unprepared("CREATE TRIGGER $name BEFORE UPDATE ON $table WHEN $condition BEGIN SELECT RAISE(ABORT, 'LEDGER_IMMUTABLE'); END");
            } else {
                DB::unprepared("CREATE TRIGGER $name BEFORE UPDATE ON $table FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LEDGER_IMMUTABLE'; END IF; END");
            }
        }
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['audit_logs', 'integration_events', 'benefit_usages', 'payments', 'invoices', 'pet_product_slots', 'contract_documents', 'subscriptions', 'proposals', 'benefit_rules', 'product_versions', 'products', 'pets', 'user_franchises'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('franchise_id');
            $t->dropConstrainedForeignId('customer_id');
            $t->dropColumn(['role', 'active']);
        });
        Schema::dropIfExists('customers');
        Schema::dropIfExists('franchises');
        Schema::enableForeignKeyConstraints();
    }
};

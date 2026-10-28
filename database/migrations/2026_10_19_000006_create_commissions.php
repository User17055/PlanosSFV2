<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Percentual por produto com histórico: mudar o percentual não altera comissões já calculadas.
        Schema::create('commission_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('rate_basis_points');
            $t->string('calculation_type', 16)->default('percent');
            $t->unsignedBigInteger('fixed_amount_cents')->nullable();
            $t->timestamp('effective_from');
            $t->boolean('is_demo')->default(false);
            $t->string('note', 180)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['product_id', 'effective_from']);
        });

        // Uma comissão por assinatura, nascida do primeiro pagamento confirmado.
        Schema::create('commissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('proposal_id')->constrained()->restrictOnDelete();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->foreignId('originating_franchise_id')->constrained('franchises')->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_version_id')->constrained()->restrictOnDelete();
            $t->string('category', 24);
            $t->foreignId('sold_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('beneficiary_type', 16);
            $t->foreignId('beneficiary_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('beneficiary_name', 180);
            $t->string('rule', 32);
            $t->json('origin_snapshot');
            $t->unsignedBigInteger('base_cents');
            $t->foreignId('commission_rate_id')->nullable()->constrained('commission_rates')->restrictOnDelete();
            $t->unsignedInteger('rate_basis_points')->nullable();
            $t->string('calculation_type', 16)->nullable();
            $t->unsignedBigInteger('fixed_amount_cents')->nullable();
            $t->unsignedBigInteger('amount_cents')->default(0);
            $t->string('status', 16);
            $t->timestamp('earned_at');
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('payout_reference', 120)->nullable();
            $t->timestamp('reversed_at')->nullable();
            $t->string('reversal_reason', 180)->nullable();
            $t->boolean('clawback')->default(false);
            $t->boolean('is_demo')->default(false);
            $t->timestamps();
            $t->index(['originating_franchise_id', 'status']);
            $t->index(['beneficiary_user_id', 'status']);
            $t->index(['earned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('commission_rates');
    }
};

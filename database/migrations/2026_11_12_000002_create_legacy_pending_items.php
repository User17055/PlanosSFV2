<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Item da Vindi da base antiga que ainda não tem pet: a equipe escolhe o pet em "Pets para escolher" e o contrato nasce ali.
    public function up(): void
    {
        Schema::create('legacy_pending_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_version_id')->constrained()->restrictOnDelete();
            $t->string('vindi_subscription_id');
            $t->string('vindi_product_item_id')->unique();
            $t->unsignedBigInteger('price_cents');
            $t->date('started_at')->nullable();
            $t->string('reason');
            $t->string('hint')->nullable();
            $t->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('resolution')->nullable();
            $t->timestamp('resolved_at')->nullable()->index();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_pending_items');
    }
};

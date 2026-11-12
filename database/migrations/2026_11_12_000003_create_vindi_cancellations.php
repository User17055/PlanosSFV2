<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Histórico de cancelamentos lido da Vindi (um item por pet), para o painel contar quem saiu antes e depois da importação.
    public function up(): void
    {
        Schema::create('vindi_cancellations', function (Blueprint $t) {
            $t->id();
            $t->string('vindi_subscription_id')->index();
            $t->string('vindi_product_item_id')->unique();
            $t->string('vindi_customer_id')->nullable()->index();
            $t->string('product_name');
            $t->string('category');
            $t->unsignedBigInteger('price_cents');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('cancelled_at')->index();
            // O cliente seguiu com outra assinatura do mesmo tipo (duplicada, campanha, troca de cartão): não é saída.
            $t->boolean('replaced')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vindi_cancellations');
    }
};

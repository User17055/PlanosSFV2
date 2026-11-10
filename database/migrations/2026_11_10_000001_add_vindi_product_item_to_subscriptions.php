<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Vários pets do mesmo cliente e do mesmo plano: uma assinatura Vindi com um item por pet, como as feitas pelo painel.
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->string('vindi_product_item_id')->nullable();
            $t->dropUnique(['provider_account', 'vindi_subscription_id']);
            $t->index(['provider_account', 'vindi_subscription_id']);
            $t->unique(['provider_account', 'vindi_product_item_id']);
        });
        // Uma fatura Vindi com vários pets vira uma fatura local por contrato.
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropUnique(['provider_account', 'external_id']);
            $t->unique(['provider_account', 'external_id', 'subscription_id']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropUnique(['provider_account', 'external_id', 'subscription_id']);
            $t->unique(['provider_account', 'external_id']);
        });
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->dropUnique(['provider_account', 'vindi_product_item_id']);
            $t->dropIndex(['provider_account', 'vindi_subscription_id']);
            $t->unique(['provider_account', 'vindi_subscription_id']);
            $t->dropColumn('vindi_product_item_id');
        });
    }
};

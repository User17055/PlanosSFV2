<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Contrato da base antiga (Vindi + SimplesVet): não gera comissão nem e-mail automático, e o painel o separa das vendas novas.
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->timestamp('imported_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->dropIndex(['imported_at']);
            $t->dropColumn('imported_at');
        });
    }
};

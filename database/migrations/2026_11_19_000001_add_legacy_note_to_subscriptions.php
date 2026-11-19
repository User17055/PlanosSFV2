<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato da base antiga: o que a planilha da equipe diz que está somado ao plano ("+ 2 banhos", "+ taxa") ou o nome que ela dá
 * ao plano, quando explica o valor. É só texto para quem atende: não entra na trava do contrato e não muda valor nem cobrança.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('legacy_note', 200)->nullable()->after('imported_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('legacy_note');
        });
    }
};

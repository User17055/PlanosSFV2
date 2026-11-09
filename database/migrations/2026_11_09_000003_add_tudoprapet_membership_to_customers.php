<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Situação do tutor no grupo "Plano São Francisco" da Tudo pra Pet, como a API dela respondeu por último.
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->string('tudoprapet_status', 16)->nullable();
            $t->timestamp('tudoprapet_synced_at')->nullable();
            $t->string('tudoprapet_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn(['tudoprapet_status', 'tudoprapet_synced_at', 'tudoprapet_error']);
        });
    }
};

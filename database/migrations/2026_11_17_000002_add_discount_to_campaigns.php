<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Depois das mensalidades grátis, um desconto em porcentagem nas mensalidades seguintes (ex.: Black Friday: 2ª grátis e 3ª com 30%).
        Schema::table('campaigns', function (Blueprint $table) {
            $table->unsignedTinyInteger('discount_percent')->default(0)->after('free_months');
            $table->unsignedTinyInteger('discount_months')->default(0)->after('discount_percent');
        });
        // A proposta guarda o desconto do dia em que foi aberta, como já faz com os meses grátis.
        Schema::table('proposals', function (Blueprint $table) {
            $table->unsignedTinyInteger('discount_percent')->default(0)->after('free_months');
            $table->unsignedTinyInteger('discount_months')->default(0)->after('discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', fn (Blueprint $table) => $table->dropColumn(['discount_percent', 'discount_months']));
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn(['discount_percent', 'discount_months']));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Link de autocadastro leva a campanha que o vendedor marcou ao gerar o link (vazio = sem campanha).
    public function up(): void
    {
        Schema::table('marketing_links', fn (Blueprint $table) => $table->unsignedBigInteger('campaign_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('marketing_links', fn (Blueprint $table) => $table->dropColumn('campaign_id'));
    }
};

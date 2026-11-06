<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Foto do contato no Digisac, guardada no disco privado; a data diz quando o Digisac foi consultado.
            $table->string('photo_path', 120)->nullable();
            $table->timestamp('photo_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['photo_path', 'photo_synced_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Telas que o funcionário pode abrir. Vazio (null) = tudo o que o perfil dele permite. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('areas')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('areas');
        });
    }
};

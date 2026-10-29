<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', fn (Blueprint $table) => $table->string('photo_path')->nullable());
    }

    public function down(): void
    {
        Schema::table('pets', fn (Blueprint $table) => $table->dropColumn('photo_path'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->string('rg', 40)->nullable());
        Schema::table('pets', fn (Blueprint $table) => $table->string('color', 100)->nullable());
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('rg'));
        Schema::table('pets', fn (Blueprint $table) => $table->dropColumn('color'));
    }
};

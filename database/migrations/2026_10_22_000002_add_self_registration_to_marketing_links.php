<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketing_links', fn(Blueprint $table) => $table->boolean('self_registration')->default(false));
    }

    public function down(): void
    {
        Schema::table('marketing_links', fn(Blueprint $table) => $table->dropColumn('self_registration'));
    }
};

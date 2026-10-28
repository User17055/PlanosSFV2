<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            // The Vindi customer this CPF is linked to, so every plan of the tutor reuses the same record.
            $t->string('vindi_customer_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->dropIndex(['vindi_customer_id']);
            $t->dropColumn('vindi_customer_id');
        });
    }
};

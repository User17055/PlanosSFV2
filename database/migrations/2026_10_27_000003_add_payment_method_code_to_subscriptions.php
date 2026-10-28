<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $t) {
            // Vindi payment method chosen by the tutor at checkout; used for the entry and every monthly bill.
            $t->string('payment_method_code', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->dropColumn('payment_method_code');
        });
    }
};

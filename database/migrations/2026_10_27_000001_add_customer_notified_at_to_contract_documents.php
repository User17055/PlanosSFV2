<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_documents', function (Blueprint $t) {
            $t->timestamp('customer_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contract_documents', function (Blueprint $t) {
            $t->dropColumn('customer_notified_at');
        });
    }
};

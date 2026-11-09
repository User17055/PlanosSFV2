<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Conta de cliente da própria pessoa da administração; vinculada só com a senha dessa conta.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('linked_customer_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('linked_customer_user_id');
        });
    }
};

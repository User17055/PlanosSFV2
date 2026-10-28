<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vindi_checkouts',function(Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('provider_account');
            $table->string('reference')->unique();
            $table->string('status')->default('processing');
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('vindi_checkouts'); }
};

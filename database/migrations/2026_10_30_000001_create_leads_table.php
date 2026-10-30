<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('originating_franchise_id')->constrained('franchises')->restrictOnDelete();
            $t->string('external_id', 80)->nullable()->unique();
            $t->string('name', 150);
            $t->string('phone', 30)->nullable();
            $t->string('email', 190)->nullable();
            $t->string('pet_name', 100)->nullable();
            $t->string('source', 40)->default('facebook_lead_ads');
            $t->string('campaign', 160)->nullable();
            $t->string('status', 20)->default('new');
            $t->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('contacted_at')->nullable();
            $t->json('extra')->nullable();
            $t->timestamps();
            $t->index(['originating_franchise_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};

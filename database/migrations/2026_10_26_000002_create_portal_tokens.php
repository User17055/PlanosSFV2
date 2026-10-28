<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('portal_tokens',function(Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash',64)->unique();
            $t->string('password_fingerprint',64);
            $t->timestamp('expires_at')->index();
            $t->unsignedBigInteger('review_proposal_id')->nullable();
            $t->string('review_fingerprint',64)->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('portal_tokens'); }
};

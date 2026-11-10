<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // O SimplesVet exige o sexo do titular e a esterilização do pet para cadastrar; as fichas de lá ficam ligadas às daqui.
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->string('sex', 10)->nullable();
            $t->string('simplesvet_id', 30)->nullable();
        });
        Schema::table('pets', function (Blueprint $t) {
            $t->boolean('neutered')->nullable();
            $t->string('simplesvet_id', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pets', fn (Blueprint $t) => $t->dropColumn(['neutered', 'simplesvet_id']));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['sex', 'simplesvet_id']));
    }
};

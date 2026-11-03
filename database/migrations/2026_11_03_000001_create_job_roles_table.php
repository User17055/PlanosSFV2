<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Cargos da equipe: um nível de acesso e as opções liberadas dentro dele. Funcionário sem cargo continua só com o perfil. */
    public function up(): void
    {
        Schema::create('job_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('base_role', 20);
            $table->json('permissions');
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('job_role_id')->nullable()->after('areas')->constrained('job_roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_role_id');
        });
        Schema::dropIfExists('job_roles');
    }
};

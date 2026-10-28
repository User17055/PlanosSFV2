<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_code',40)->nullable()->unique();
            $table->string('phone',30)->nullable();
            $table->string('job_title',100)->nullable();
            $table->date('hired_at')->nullable();
            $table->unsignedBigInteger('monthly_sales_target_cents')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users',function (Blueprint $table) {
            $table->dropUnique(['employee_code']);
            $table->dropColumn(['employee_code','phone','job_title','hired_at','monthly_sales_target_cents']);
        });
    }
};

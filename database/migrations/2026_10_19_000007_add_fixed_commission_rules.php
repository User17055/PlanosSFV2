<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('commission_rates', 'calculation_type')) {
            Schema::table('commission_rates', function (Blueprint $table) {
                $table->string('calculation_type', 16)->default('percent')->after('rate_basis_points');
                $table->unsignedBigInteger('fixed_amount_cents')->nullable()->after('calculation_type');
            });
        }
        if (! Schema::hasColumn('commissions', 'calculation_type')) {
            Schema::table('commissions', function (Blueprint $table) {
                $table->string('calculation_type', 16)->nullable()->after('rate_basis_points');
                $table->unsignedBigInteger('fixed_amount_cents')->nullable()->after('calculation_type');
            });
        }
    }

    public function down(): void
    {
        Schema::table('commissions', fn (Blueprint $table) => $table->dropColumn(['calculation_type', 'fixed_amount_cents']));
        Schema::table('commission_rates', fn (Blueprint $table) => $table->dropColumn(['calculation_type', 'fixed_amount_cents']));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_versions', fn(Blueprint $table) => $table->json('contract_layout')->nullable());
        if (DB::getDriverName()==='sqlite') DB::unprepared("CREATE TRIGGER product_versions_layout_immutable BEFORE UPDATE ON product_versions WHEN OLD.published_at IS NOT NULL AND OLD.contract_layout IS NOT NEW.contract_layout BEGIN SELECT RAISE(ABORT, 'CONTRACT_IMMUTABLE'); END");
        if (DB::getDriverName()==='mysql') DB::unprepared("CREATE TRIGGER product_versions_layout_immutable BEFORE UPDATE ON product_versions FOR EACH ROW BEGIN IF OLD.published_at IS NOT NULL AND NOT (OLD.contract_layout <=> NEW.contract_layout) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CONTRACT_IMMUTABLE'; END IF; END");
    }
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS product_versions_layout_immutable');
        Schema::table('product_versions', fn(Blueprint $table) => $table->dropColumn('contract_layout'));
    }
};

<?php

use Database\MigrationSupport\ContractGuardInstaller;
use Illuminate\Database\Migrations\Migration;

require_once __DIR__.'/support/ContractGuardInstaller.php';

return new class extends Migration
{
    public function up(): void
    {
        // Repair existing installations without updating a single contractual record.
        (new ContractGuardInstaller)->restore();
    }

    public function down(): void
    {
        // This repairs lost integrity protection; rolling back must never reopen accepted contracts.
        (new ContractGuardInstaller)->restore();
    }
};

<?php

namespace App\Services\Contracts;

use App\Models\ProductVersion;

class EconomicContractInstaller
{
    public function install(): ProductVersion
    {
        return app(HealthPlanInstaller::class)->installOne('economico');
    }
}

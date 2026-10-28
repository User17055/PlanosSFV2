<?php

namespace App\Services\Integrations;

use App\Models\Subscription;

interface PartnerAdapter
{
    /** Completion must never be claimed without an authoritative partner response. */
    public function synchronize(Subscription $subscription): array;
}

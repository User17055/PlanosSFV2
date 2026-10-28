<?php

namespace App\Services\Domain;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    public static function record(string $action, Model $entity, ?User $actor = null, array $data = [], ?string $reason = null, ?int $franchiseId = null): void
    {
        AuditLog::create(['actor_id' => $actor?->id, 'franchise_id' => $franchiseId ?? $entity->originating_franchise_id, 'action' => $action, 'entity_type' => $entity->getMorphClass(), 'entity_id' => $entity->id, 'data' => $data, 'reason' => $reason, 'correlation_id' => request()?->header('X-Correlation-ID')]);
    }
}

<?php

namespace App\Services\Integrations;

use App\Models\Subscription;

class SimplesVetAdapter implements PartnerAdapter
{
    public function synchronize(Subscription $subscription): array
    {
        throw new IntegrationPendingException('SimplesVet: tarefa manual necessária. API e autorização de escrita pendentes; nenhuma alteração externa foi realizada.');
    }
}

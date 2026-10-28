<?php

namespace App\Services\Integrations;

use App\Models\Subscription;

class TudoPraPetAdapter implements PartnerAdapter
{
    public function synchronize(Subscription $subscription): array
    {
        throw new IntegrationPendingException('TudoPraPet: tarefa manual necessária. Criação de conta e grupo comercial dependem de API autorizada; nenhuma conta foi criada.');
    }
}

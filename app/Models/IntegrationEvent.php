<?php

namespace App\Models;

class IntegrationEvent extends DomainModel
{
    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}

<?php

namespace App\Models;

class VindiCancellation extends DomainModel
{
    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'cancelled_at' => 'datetime', 'replaced' => 'boolean'];
    }
}

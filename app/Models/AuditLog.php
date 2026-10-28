<?php

namespace App\Models;

class AuditLog extends DomainModel
{
    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function franchise()
    {
        return $this->belongsTo(Franchise::class);
    }
}

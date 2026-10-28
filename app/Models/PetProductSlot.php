<?php

namespace App\Models;

class PetProductSlot extends DomainModel
{
    protected function casts(): array
    {
        return ['reserved_at' => 'datetime', 'expires_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function pet()
    {
        return $this->belongsTo(Pet::class);
    }

    public function proposal()
    {
        return $this->belongsTo(Proposal::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}

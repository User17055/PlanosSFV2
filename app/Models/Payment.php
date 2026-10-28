<?php

namespace App\Models;

class Payment extends DomainModel
{
    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}

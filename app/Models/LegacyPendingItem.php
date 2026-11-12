<?php

namespace App\Models;

class LegacyPendingItem extends DomainModel
{
    protected function casts(): array
    {
        return ['started_at' => 'date', 'resolved_at' => 'datetime'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function productVersion()
    {
        return $this->belongsTo(ProductVersion::class);
    }
}

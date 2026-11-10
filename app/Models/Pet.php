<?php

namespace App\Models;

class Pet extends DomainModel
{
    protected function casts(): array
    {
        return ['birth_date' => 'date', 'active' => 'boolean', 'neutered' => 'boolean'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function slots()
    {
        return $this->hasMany(PetProductSlot::class);
    }
}

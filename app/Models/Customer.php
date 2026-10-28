<?php

namespace App\Models;

class Customer extends DomainModel
{
    protected function casts(): array
    {
        return ['address' => 'array', 'acquisition_profile'=>'array', 'active' => 'boolean', 'contact_verified_at' => 'datetime', 'financial_checked_at' => 'datetime'];
    }

    public function pets()
    {
        return $this->hasMany(Pet::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function proposals()
    {
        return $this->hasMany(Proposal::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function originatingFranchise()
    {
        return $this->belongsTo(Franchise::class, 'originating_franchise_id');
    }
}

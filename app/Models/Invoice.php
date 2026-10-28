<?php

namespace App\Models;

class Invoice extends DomainModel
{
    protected function casts(): array
    {
        return ['is_initial' => 'boolean', 'due_at' => 'datetime', 'paid_at' => 'datetime', 'confirmed_at' => 'datetime', 'competence_start' => 'datetime', 'competence_end' => 'datetime', 'provider_updated_at' => 'datetime'];
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function originatingFranchise()
    {
        return $this->belongsTo(Franchise::class, 'originating_franchise_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function getOutstandingCentsAttribute(): int
    {
        return max(0, $this->amount_cents - $this->paid_cents);
    }
}

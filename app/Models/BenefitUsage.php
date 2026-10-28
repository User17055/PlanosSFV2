<?php

namespace App\Models;

class BenefitUsage extends DomainModel
{
    protected function casts(): array
    {
        return ['rule_snapshot' => 'array', 'period_start' => 'datetime', 'period_end' => 'datetime', 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function benefitRule()
    {
        return $this->belongsTo(BenefitRule::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function pet()
    {
        return $this->belongsTo(Pet::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function servicingFranchise()
    {
        return $this->belongsTo(Franchise::class, 'servicing_franchise_id');
    }

    public function originalUsage()
    {
        return $this->belongsTo(self::class, 'original_usage_id');
    }

    public function reversals()
    {
        return $this->hasMany(self::class, 'original_usage_id')->where('status', 'reversal');
    }
}

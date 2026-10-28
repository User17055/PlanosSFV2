<?php

namespace App\Models;

class MarketingLink extends DomainModel
{
    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return ['self_registration'=>'boolean','token_encrypted' => 'encrypted', 'owner_id' => 'integer', 'created_by' => 'integer', 'originating_franchise_id' => 'integer', 'product_version_id' => 'integer', 'referrer_customer_id' => 'integer', 'expires_at' => 'datetime'];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function originatingFranchise()
    {
        return $this->belongsTo(Franchise::class, 'originating_franchise_id');
    }

    public function productVersion()
    {
        return $this->belongsTo(ProductVersion::class);
    }

    public function referrerCustomer()
    {
        return $this->belongsTo(Customer::class, 'referrer_customer_id');
    }

    public function entryTouches()
    {
        return $this->hasMany(AttributionTouch::class);
    }

    public function proposals()
    {
        return $this->hasMany(Proposal::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
}

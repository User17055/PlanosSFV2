<?php

namespace App\Models;

class AttributionTouch extends DomainModel
{
    protected $hidden = ['session_key_hash'];

    protected function casts(): array
    {
        return ['marketing_link_id' => 'integer', 'owner_id' => 'integer', 'originating_franchise_id' => 'integer', 'product_version_id' => 'integer', 'referrer_customer_id' => 'integer', 'touched_at' => 'datetime', 'metadata_snapshot' => 'array'];
    }

    public function marketingLink()
    {
        return $this->belongsTo(MarketingLink::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
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
}

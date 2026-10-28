<?php

namespace App\Models;

use App\Services\Attribution\AttributionData;

class Proposal extends DomainModel
{
    protected array $immutable = ['customer_id', 'pet_id', 'product_version_id', 'category', 'locked_at', 'snapshot', 'price_cents', 'originating_franchise_id', 'signature_method', 'requested_start_at', 'accepted_at', 'acceptance_evidence', ...AttributionData::FIELDS];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'acceptance_evidence' => 'array', 'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'locked_at' => 'datetime', 'requested_start_at' => 'datetime', 'link_expires_at' => 'datetime', 'link_revoked_at' => 'datetime', 'attribution_snapshot' => 'array', 'first_touch_at' => 'datetime', 'last_touch_at' => 'datetime', 'marketing_link_id' => 'integer', 'link_owner_id' => 'integer', 'referrer_customer_id' => 'integer', 'first_touch_id' => 'integer', 'last_touch_id' => 'integer'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function pet()
    {
        return $this->belongsTo(Pet::class);
    }

    public function productVersion()
    {
        return $this->belongsTo(ProductVersion::class);
    }

    public function originatingFranchise()
    {
        return $this->belongsTo(Franchise::class, 'originating_franchise_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }

    public function documents()
    {
        return $this->hasMany(ContractDocument::class);
    }

    public function marketingLink()
    {
        return $this->belongsTo(MarketingLink::class);
    }

    public function linkOwner()
    {
        return $this->belongsTo(User::class, 'link_owner_id');
    }

    public function referrerCustomer()
    {
        return $this->belongsTo(Customer::class, 'referrer_customer_id');
    }

    public function firstTouch()
    {
        return $this->belongsTo(AttributionTouch::class, 'first_touch_id');
    }

    public function lastTouch()
    {
        return $this->belongsTo(AttributionTouch::class, 'last_touch_id');
    }
}

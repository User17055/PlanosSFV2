<?php

namespace App\Models;

use App\Services\Attribution\AttributionData;

class Subscription extends DomainModel
{
    public function cancellationRequest()
    {
        return $this->hasOne(CancellationRequest::class);
    }

    public const TERMINAL = ['cancelled', 'ended'];

    protected array $immutable = ['proposal_id', 'customer_id', 'pet_id', 'product_version_id', 'category', 'locked_at', 'contract_snapshot', 'price_cents', 'originating_franchise_id', 'requested_start_at', ...AttributionData::FIELDS];

    protected function casts(): array
    {
        return ['contract_snapshot' => 'array', 'locked_at' => 'datetime', 'requested_start_at' => 'datetime', 'effective_start_at' => 'datetime', 'activated_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled_at' => 'datetime', 'financial_checked_at' => 'datetime', 'attribution_snapshot' => 'array', 'first_touch_at' => 'datetime', 'last_touch_at' => 'datetime', 'marketing_link_id' => 'integer', 'link_owner_id' => 'integer', 'referrer_customer_id' => 'integer', 'first_touch_id' => 'integer', 'last_touch_id' => 'integer'];
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

    public function proposal()
    {
        return $this->belongsTo(Proposal::class);
    }

    public function originatingFranchise()
    {
        return $this->belongsTo(Franchise::class, 'originating_franchise_id');
    }

    public function documents()
    {
        return $this->hasMany(ContractDocument::class);
    }

    public function contractDocuments()
    {
        return $this->hasMany(ContractDocument::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function usages()
    {
        return $this->hasMany(BenefitUsage::class);
    }

    public function slot()
    {
        return $this->hasOne(PetProductSlot::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
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

<?php

namespace App\Models;

class ProductVersion extends DomainModel
{
    public static function localPreviewEnabled(): bool
    {
        return app()->environment('local') && config('plans.demo');
    }

    public function scopeOffered(\Illuminate\Database\Eloquent\Builder $query)
    {
        return $query->whereIn('status',self::localPreviewEnabled()?['published','local_preview']:['published'])
            ->whereNotNull('published_at')->whereHas('product',fn($q)=>$q->where('active',true))
            ->where(fn($q)=>$q->whereNull('offer_starts_at')->orWhere('offer_starts_at','<=',now()))
            ->where(fn($q)=>$q->whereNull('offer_ends_at')->orWhere('offer_ends_at','>',now()));
    }

    public function scopePreferredOffers(\Illuminate\Database\Eloquent\Builder $query)
    {
        $real=self::query()->offered()->where('is_demo',false)->whereNotNull('contract_layout')->exists();
        return $query->offered()->when($real,fn($q)=>$q->where('is_demo',false));
    }

    public function isOffered(): bool
    {
        return in_array($this->status,self::localPreviewEnabled()?['published','local_preview']:['published'],true)
            && $this->published_at && $this->product->active && !$this->offer_starts_at?->isFuture() && !$this->offer_ends_at?->isPast();
    }

    protected function casts(): array
    {
        return ['contract_layout'=>'array','species' => 'array', 'allowed_franchise_ids' => 'array', 'is_demo' => 'boolean', 'published_at' => 'datetime', 'offer_starts_at' => 'datetime', 'offer_ends_at' => 'datetime'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function benefitRules()
    {
        return $this->hasMany(BenefitRule::class);
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

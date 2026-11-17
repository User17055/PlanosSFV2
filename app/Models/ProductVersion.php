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
        $statuses=self::localPreviewEnabled()?['published','local_preview']:['published'];
        $table=$query->getModel()->getTable();
        return $query->whereIn('status',$statuses)
            ->whereNotNull('published_at')->whereHas('product',fn($q)=>$q->where('active',true))
            ->where(fn($q)=>$q->whereNull('offer_starts_at')->orWhere('offer_starts_at','<=',now()))
            ->where(fn($q)=>$q->whereNull('offer_ends_at')->orWhere('offer_ends_at','>',now()))
            // Publicar uma versão nova tira a anterior da venda; quem já assinou continua com a dele.
            ->whereNotExists(fn($q)=>$q->selectRaw('1')->from($table.' as newer')
                ->whereColumn('newer.product_id',$table.'.product_id')->whereColumn('newer.is_demo',$table.'.is_demo')
                ->whereColumn('newer.version','>',$table.'.version')->whereIn('newer.status',$statuses)->whereNotNull('newer.published_at')
                ->where(fn($w)=>$w->whereNull('newer.offer_starts_at')->orWhere('newer.offer_starts_at','<=',now()))
                ->where(fn($w)=>$w->whereNull('newer.offer_ends_at')->orWhere('newer.offer_ends_at','>',now())));
    }

    /** A versão à venda hoje para o mesmo plano: esta ou a publicada mais nova. Null se o plano saiu da venda. */
    public function currentOffer(): ?self
    {
        return self::query()->offered()->with('product')->where('product_id',$this->product_id)
            ->where('is_demo',$this->is_demo)->orderByDesc('version')->first();
    }

    /** Link, sessão ou página antiga que guardou o id de uma versão anterior segue para a que está à venda. */
    public static function currentOfferFor(?int $id): ?self
    {
        return $id ? self::find($id)?->currentOffer() : null;
    }

    public function scopePreferredOffers(\Illuminate\Database\Eloquent\Builder $query)
    {
        $real=self::query()->offered()->where('is_demo',false)->whereNotNull('contract_layout')->exists();
        return $query->offered()->when($real,fn($q)=>$q->where('is_demo',false));
    }

    public function isOffered(): bool
    {
        return in_array($this->status,self::localPreviewEnabled()?['published','local_preview']:['published'],true)
            && $this->published_at && $this->product->active && !$this->offer_starts_at?->isFuture() && !$this->offer_ends_at?->isPast()
            && !self::query()->offered()->where('product_id',$this->product_id)->where('is_demo',$this->is_demo)->where('version','>',$this->version)->exists();
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

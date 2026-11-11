<?php

namespace App\Models;

use App\Services\Attribution\AttributionData;

class Proposal extends DomainModel
{
    protected array $immutable = ['customer_id', 'pet_id', 'product_version_id', 'category', 'locked_at', 'snapshot', 'price_cents', 'originating_franchise_id', 'signature_method', 'requested_start_at', 'accepted_at', 'acceptance_evidence', 'campaign_id', 'free_months', ...AttributionData::FIELDS];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'acceptance_evidence' => 'array', 'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'locked_at' => 'datetime', 'requested_start_at' => 'datetime', 'link_expires_at' => 'datetime', 'link_revoked_at' => 'datetime', 'attribution_snapshot' => 'array', 'first_touch_at' => 'datetime', 'last_touch_at' => 'datetime', 'marketing_link_id' => 'integer', 'link_owner_id' => 'integer', 'referrer_customer_id' => 'integer', 'first_touch_id' => 'integer', 'last_touch_id' => 'integer', 'campaign_id' => 'integer', 'free_months' => 'integer'];
    }

    public const STAGES = ['assinar' => 'Falta assinar', 'pagar' => 'Assinado, falta pagar', 'ativar' => 'Pago, falta ativar', 'ativo' => 'Plano ativo', 'encerrada' => 'Encerrada'];

    /** Etapa da contratação para a equipe: assinar, pagar, ativar, ativo. Precisa de subscription.invoices carregado para não consultar por linha. */
    public function stage(): string
    {
        $s = $this->subscription;
        if (in_array($this->status, ['cancelled', 'expired'], true) || $s?->isTerminal() || (! $s && $this->expires_at->isPast())) {
            return 'encerrada';
        }
        if ($s?->activated_at) {
            return 'ativo';
        }
        if (! $this->accepted_at && $s?->document_status !== 'valid') {
            return 'assinar';
        }
        return $s?->invoices->contains(fn ($i) => $i->is_initial && $i->status === 'paid') ? 'ativar' : 'pagar';
    }

    /** Mesma regra de stage(), em consulta, para o filtro da lista. */
    public function scopeInStage($query, string $stage)
    {
        $terminal = Subscription::TERMINAL;
        $paidInitial = fn ($i) => $i->where('is_initial', true)->where('status', 'paid');
        $signed = fn ($q) => $q->where(fn ($w) => $w->whereNotNull('accepted_at')->orWhereHas('subscription', fn ($s) => $s->where('document_status', 'valid')));
        $open = fn ($q) => $q->whereNotIn('status', ['cancelled', 'expired'])
            ->whereDoesntHave('subscription', fn ($s) => $s->whereIn('status', $terminal))
            ->where(fn ($w) => $w->whereHas('subscription')->orWhere('expires_at', '>=', now()));
        $notActive = fn ($q) => $q->whereDoesntHave('subscription', fn ($s) => $s->whereNotNull('activated_at'));

        return match ($stage) {
            'encerrada' => $query->where(fn ($w) => $w->whereIn('status', ['cancelled', 'expired'])
                ->orWhereHas('subscription', fn ($s) => $s->whereIn('status', $terminal))
                ->orWhere(fn ($x) => $x->whereDoesntHave('subscription')->where('expires_at', '<', now()))),
            'ativo' => $open($query)->whereHas('subscription', fn ($s) => $s->whereNotNull('activated_at')),
            'assinar' => $notActive($open($query))->whereNull('accepted_at')->whereDoesntHave('subscription', fn ($s) => $s->where('document_status', 'valid')),
            'ativar' => $signed($notActive($open($query)))->whereHas('subscription.invoices', $paidInitial),
            'pagar' => $signed($notActive($open($query)))->whereDoesntHave('subscription.invoices', $paidInitial),
            default => $query,
        };
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

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
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

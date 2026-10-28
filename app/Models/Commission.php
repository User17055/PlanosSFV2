<?php

namespace App\Models;

class Commission extends DomainModel
{
    public const STATUSES = ['payable' => 'A pagar', 'awaiting_rate' => 'Aguardando regra', 'held' => 'Em contestação', 'paid' => 'Paga', 'reversed' => 'Estornada', 'not_applicable' => 'Sem responsável comissionado'];

    public const RULES = [
        'seller_proposal' => 'Equipe que fez a proposta',
        'link_owner' => 'Tutor contratou pelo link deste responsável',
        'site_self_service' => 'Contratação direta pelo site',
        'customer_referral' => 'Indicação de tutor, sem responsável da equipe',
    ];

    protected function casts(): array
    {
        return ['origin_snapshot' => 'array', 'earned_at' => 'datetime', 'paid_at' => 'datetime', 'reversed_at' => 'datetime', 'clawback' => 'boolean', 'is_demo' => 'boolean', 'fixed_amount_cents' => 'integer'];
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function proposal()
    {
        return $this->belongsTo(Proposal::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function originatingFranchise()
    {
        return $this->belongsTo(Franchise::class, 'originating_franchise_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productVersion()
    {
        return $this->belongsTo(ProductVersion::class);
    }

    public function soldBy()
    {
        return $this->belongsTo(User::class, 'sold_by_id');
    }

    public function beneficiary()
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function rate()
    {
        return $this->belongsTo(CommissionRate::class, 'commission_rate_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getRuleLabelAttribute(): string
    {
        return self::RULES[$this->rule] ?? $this->rule;
    }
}

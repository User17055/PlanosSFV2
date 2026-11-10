<?php

namespace App\Models;

class Customer extends DomainModel
{
    protected static function booted(): void
    {
        parent::booted();
        // Endereço preenchido ou corrigido aqui, ou cliente recém-ligado à Vindi: o cadastro de lá recebe o CEP e o endereço.
        static::updated(function (self $customer) {
            if ($customer->vindi_customer_id && $customer->wasChanged(['address', 'vindi_customer_id']) && \App\Services\CustomerAddress::vindi($customer->address)) {
                \App\Jobs\SyncVindiCustomerJob::dispatch($customer->id)->afterCommit();
            }
        });
    }

    protected function casts(): array
    {
        return ['address' => 'array', 'acquisition_profile'=>'array', 'active' => 'boolean', 'contact_verified_at' => 'datetime', 'financial_checked_at' => 'datetime'];
    }

    public function pets()
    {
        return $this->hasMany(Pet::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function proposals()
    {
        return $this->hasMany(Proposal::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function originatingFranchise()
    {
        return $this->belongsTo(Franchise::class, 'originating_franchise_id');
    }

    /** Acesso do titular: o que ele já tem ou um novo, com senha aleatória que ele troca pelo link. Sem e-mail válido ou com e-mail de outra conta, não há acesso. */
    public function accessUser(): ?User
    {
        if (! $this->active) return null;
        $user = User::where('customer_id', $this->id)->where('role', 'customer')->first();
        if ($user) return $user->active ? $user : null;
        if (! filter_var($this->email, FILTER_VALIDATE_EMAIL) || User::where('email', $this->email)->exists()) return null;
        return User::create(['name' => $this->name, 'email' => $this->email, 'password' => \Illuminate\Support\Str::random(40), 'role' => 'customer', 'customer_id' => $this->id, 'active' => true]);
    }
}

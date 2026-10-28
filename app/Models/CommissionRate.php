<?php

namespace App\Models;

class CommissionRate extends DomainModel
{
    protected function casts(): array
    {
        return ['effective_from' => 'datetime', 'is_demo' => 'boolean', 'fixed_amount_cents' => 'integer'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getPercentAttribute(): string
    {
        return number_format($this->rate_basis_points / 100, 2, ',', '.').'%';
    }
}

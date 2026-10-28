<?php

namespace App\Models;

class BenefitRule extends DomainModel
{
    protected function casts(): array
    {
        return ['allowed_franchise_ids' => 'array', 'requires_authorization' => 'boolean'];
    }

    public function productVersion()
    {
        return $this->belongsTo(ProductVersion::class);
    }

    public function usages()
    {
        return $this->hasMany(BenefitUsage::class);
    }
}

<?php

namespace App\Models;

class Product extends DomainModel
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function versions()
    {
        return $this->hasMany(ProductVersion::class);
    }

    public function publishedVersions()
    {
        return $this->versions()->where('status', 'published')->whereNotNull('published_at');
    }

    public function commissionRates()
    {
        return $this->hasMany(CommissionRate::class);
    }
}

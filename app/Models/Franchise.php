<?php

namespace App\Models;

class Franchise extends DomainModel
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_franchises')->withPivot('active', 'permissions');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'originating_franchise_id');
    }
}

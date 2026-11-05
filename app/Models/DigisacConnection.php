<?php

namespace App\Models;

class DigisacConnection extends DomainModel
{
    protected $hidden = ['api_token'];

    protected function casts(): array
    {
        return ['api_token' => 'encrypted', 'verified_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

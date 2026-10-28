<?php

namespace App\Models;

class ContractDocument extends DomainModel
{
    protected function casts(): array
    {
        return ['evidence' => 'array', 'accepted_at' => 'datetime', 'validated_at' => 'datetime', 'customer_notified_at' => 'datetime'];
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function proposal()
    {
        return $this->belongsTo(Proposal::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}

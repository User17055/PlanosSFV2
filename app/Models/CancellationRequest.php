<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CancellationRequest extends Model
{
    protected $fillable=['subscription_id','requested_by','reason','status','resolved_at','resolved_by'];
    protected function casts(): array { return ['resolved_at'=>'datetime']; }
    public function subscription() { return $this->belongsTo(Subscription::class); }
}

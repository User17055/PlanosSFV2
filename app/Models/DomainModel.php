<?php

namespace App\Models;

use App\Services\Domain\DomainException;
use Illuminate\Database\Eloquent\Model;

abstract class DomainModel extends Model
{
    protected $guarded = ['id'];

    protected array $immutable = [];

    protected static function booted(): void
    {
        static::updating(function (self $m) {
            if ($m->getRawOriginal('locked_at')) {
                foreach ($m->immutable as $f) {
                    if ($m->isDirty($f)) {
                        throw new DomainException('SUBSCRIPTION_PLAN_IMMUTABLE', 'Não é permitido alterar o objeto deste contrato. Encerre a assinatura e inicie uma nova contratação.', 422);
                    }
                }
            }
        });
    }
}

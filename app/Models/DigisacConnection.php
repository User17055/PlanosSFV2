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

    /** Conexão de um administrador central ativo: ele pode ler todas as conversas. */
    public static function admin(): ?self
    {
        return self::whereNotNull('verified_at')->whereHas('user', fn ($q) => $q->where('role', 'central_admin')->where('active', true))->orderBy('id')->first();
    }
}

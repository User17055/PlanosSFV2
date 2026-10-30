<?php

namespace App\Models;

class Lead extends DomainModel
{
    public const STATUSES = ['new' => 'Novo', 'contacted' => 'Em contato', 'converted' => 'Virou cliente', 'lost' => 'Sem interesse'];

    protected function casts(): array
    {
        return ['extra' => 'array', 'contacted_at' => 'datetime'];
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /** Link do WhatsApp: só dígitos, com o 55 do Brasil quando vier sem. */
    public function whatsappUrl(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->phone);
        if (strlen($digits) < 10) {
            return null;
        }
        return 'https://wa.me/'.(strlen($digits) <= 11 ? '55'.$digits : $digits);
    }
}

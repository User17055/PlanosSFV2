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

    /** Link do WhatsApp: só dígitos, com o 55 do Brasil quando vier sem, e a mensagem já escrita. */
    public function whatsappUrl(?string $message = null): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->phone);
        if (strlen($digits) < 10) {
            return null;
        }
        return 'https://wa.me/'.(strlen($digits) <= 11 ? '55'.$digits : $digits).($message ? '?text='.rawurlencode($message) : '');
    }

    /** (18) 99999-0000 para a tela; número fora do padrão aparece como veio. */
    public function phoneLabel(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->phone);
        if (strlen($digits) > 11 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }
        return preg_match('/^(\d{2})(\d{4,5})(\d{4})$/', $digits, $m) ? "($m[1]) $m[2]-$m[3]" : $this->phone;
    }
}

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

    /** O Pluga pode mandar o id numérico da campanha, que não diz nada na tela; o fim do número ainda separa uma campanha da outra. */
    public function campaignLabel(): string
    {
        return ctype_digit((string) $this->campaign) ? 'Campanha nº …'.substr($this->campaign, -6) : ($this->campaign ?: 'Anúncio');
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

    /** Primeira mensagem do WhatsApp, já escrita para a equipe só enviar. */
    public function greeting(): string
    {
        return 'Olá, '.explode(' ', trim($this->name))[0].'! Aqui é da São Francisco. Vi que você pediu informações sobre os planos para o seu pet. Posso te ajudar?';
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

<?php

namespace App\Models;

class Lead extends DomainModel
{
    public const STATUSES = ['new' => 'Esperando', 'contacted' => 'Processando', 'converted' => 'Finalizado', 'lost' => 'Sem interesse'];

    /** Finalizado é quem virou cliente; Sem interesse é quem não virou. */
    public const CONVERSIONS = ['Converteu' => ['converted'], 'Ainda não converteu' => ['new', 'contacted'], 'Não converteu' => ['lost']];

    public const TRAFFIC = ['Pago', 'Orgânico'];

    /** Estes perfis veem todos os leads e escolhem com quem cada um fica. */
    public const SEE_ALL = ['central_admin', 'manager'];

    /** Campos de "extra" em que o Pluga pode mandar a rede de origem. */
    public const CHANNEL_FIELDS = ['plataforma', 'platform', 'origem', 'canal', 'rede_social'];

    protected function casts(): array
    {
        return ['extra' => 'array', 'contacted_at' => 'datetime'];
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /** Fora os administradores, cada pessoa vê os seus leads e a fila: os que ainda não são de ninguém. */
    public function scopeVisibleTo($query, User $user)
    {
        return in_array($user->role, self::SEE_ALL, true) ? $query : $query->where(fn ($w) => $w->whereNull('handled_by')->orWhere('handled_by', $user->id));
    }

    /** O Pluga pode mandar o id numérico da campanha, que não diz nada na tela; o fim do número ainda separa uma campanha da outra. */
    public function campaignLabel(): string
    {
        if (ctype_digit((string) $this->campaign)) {
            return 'Campanha nº …'.substr($this->campaign, -6);
        }
        // O marketing escreve o nome em blocos: "[Plano de Saúde] - [20,00/dia] - [Feed IG]".
        return preg_replace('/\]\s*-\s*\[/u', ' · ', trim((string) $this->campaign, "[] \t")) ?: 'Anúncio';
    }

    public function conversion(): string
    {
        return collect(self::CONVERSIONS)->search(fn ($statuses) => in_array($this->status, $statuses, true)) ?: 'Ainda não converteu';
    }

    /** Formulário do Facebook Lead Ads só existe em anúncio. Só o formulário do site pode ser orgânico: ele traz o que o cookie viu (gclid ou utm_medium de mídia paga). */
    public function traffic(): string
    {
        return $this->source !== 'site_whatsapp' || ($this->extra['trafego'] ?? null) === 'pago' ? 'Pago' : 'Orgânico';
    }

    /** Por onde a pessoa entrou. Quem veio do Google para o site tem as duas informações: entrada Site e origem Google. */
    public function entry(): string
    {
        return $this->source === 'site_whatsapp' ? 'Site' : 'Anúncio';
    }

    /** Rede de onde o lead veio. Pode chegar como sigla ("ig", "fb"); sem o campo fica vazio, porque o anúncio pode ter rodado nas duas redes. */
    public function channel(): ?string
    {
        $value = collect(self::CHANNEL_FIELDS)->map(fn ($field) => $this->extra[$field] ?? null)->filter()->first();
        if ($value === null) {
            return null;
        }
        $value = str_replace('_', ' ', $value);
        return ['ig' => 'Instagram', 'fb' => 'Facebook'][mb_strtolower($value)] ?? mb_convert_case($value, MB_CASE_TITLE);
    }

    /** Origem e logos para a tela. Sem o campo da rede, só se sabe que veio de um formulário do Facebook Lead Ads, que roda nas duas redes. */
    public function origin(): ?array
    {
        $channel = $this->channel();
        if ($channel) {
            return ['label' => $channel, 'logos' => array_values(array_filter(['instagram', 'facebook', 'google'], fn ($logo) => str_contains(mb_strtolower($channel), $logo)))];
        }
        if ($this->source !== 'facebook_lead_ads') {
            return null;
        }
        // O nome da campanha costuma dizer onde o anúncio roda ("Feed IG"). "Form. Inst." é formulário instantâneo, não Instagram.
        $text = $this->campaign.' '.($this->extra['anuncio'] ?? '');
        $logos = array_keys(array_filter(['facebook' => '/\b(fb|facebook)\b/iu', 'instagram' => '/\b(ig|instagram)\b/iu'], fn ($pattern) => preg_match($pattern, $text) === 1));
        return count($logos) === 1 ? ['label' => ucfirst($logos[0]), 'logos' => $logos] : ['label' => 'Facebook ou Instagram', 'logos' => ['facebook', 'instagram']];
    }

    /** Nome da origem no filtro da lista: "Instagram Stories" entra em "Instagram". */
    public function originGroup(): string
    {
        $origin = $this->origin();
        if (! $origin) {
            return 'Sem origem';
        }
        return count($origin['logos']) === 1 ? ucfirst($origin['logos'][0]) : $origin['label'];
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

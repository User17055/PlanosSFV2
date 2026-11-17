<?php

namespace App\Models;

/**
 * Campanha da mensalidade: a entrada é a 1ª e é cheia; as free_months seguintes são grátis (a cobrança mensal começa depois)
 * e as discount_months seguintes têm discount_percent de desconto na Vindi. Ex.: Black Friday = 2ª grátis e 3ª com 30%.
 */
class Campaign extends DomainModel
{
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'whole_site' => 'boolean', 'active' => 'boolean',
            'free_months' => 'integer', 'discount_percent' => 'integer', 'discount_months' => 'integer'];
    }

    public function proposals()
    {
        return $this->hasMany(Proposal::class);
    }

    /** Ligada e dentro do período hoje, no horário de São Paulo. */
    public function scopeCurrent($query)
    {
        $today = now('America/Sao_Paulo')->toDateString();
        return $query->where('active', true)->whereDate('starts_on', '<=', $today)
            ->where(fn ($w) => $w->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today));
    }

    /** "2ª mensalidade grátis · 3ª com 30% de desconto", contando a entrada como a 1ª. */
    public static function describe(int $free, int $percent, int $discountMonths): string
    {
        $range = fn (int $from, int $count) => match ($count) { 1 => $from.'ª', 2 => $from.'ª e '.($from + 1).'ª', default => $from.'ª a '.($from + $count - 1).'ª' };
        $parts = [];
        if ($free > 0) $parts[] = $range(2, $free).($free === 1 ? ' mensalidade grátis' : ' mensalidades grátis');
        if ($percent > 0 && $discountMonths > 0) {
            $parts[] = $range(2 + $free, $discountMonths).($free > 0 ? '' : ($discountMonths === 1 ? ' mensalidade' : ' mensalidades')).' com '.$percent.'% de desconto';
        }
        return implode(' · ', $parts) ?: 'Sem benefício';
    }

    public function benefit(): string
    {
        return self::describe((int) $this->free_months, (int) $this->discount_percent, (int) $this->discount_months);
    }

    public function label(): string
    {
        return $this->name.' · '.$this->benefit();
    }
}

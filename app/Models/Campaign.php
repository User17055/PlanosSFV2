<?php

namespace App\Models;

/** Campanha de mensalidades grátis: a primeira mensalidade depois da entrada é empurrada free_months meses. */
class Campaign extends DomainModel
{
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'whole_site' => 'boolean', 'active' => 'boolean'];
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

    public function label(): string
    {
        return $this->name.' · '.$this->free_months.' '.($this->free_months === 1 ? 'mensalidade grátis' : 'mensalidades grátis');
    }
}

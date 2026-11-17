<?php

namespace App\Models;

class BenefitRule extends DomainModel
{
    /** Lembretes da equipe gravados na descrição; o tutor nunca vê (17/11/2026). Versões já publicadas também os têm. */
    public const STAFF_NOTES = [
        'Conferir intervalo semestral antes de autorizar.',
        'Conferir histórico de doses.',
        'O texto não especifica carência própria; conferir antes de autorizar.',
    ];

    protected function casts(): array
    {
        return ['allowed_franchise_ids' => 'array', 'requires_authorization' => 'boolean'];
    }

    public function productVersion()
    {
        return $this->belongsTo(ProductVersion::class);
    }

    public function usages()
    {
        return $this->hasMany(BenefitUsage::class);
    }

    /** [texto para o tutor, lembrete da equipe]; cada parte é null quando fica vazia. */
    public static function splitDescription(?string $description): array
    {
        $public = (string) $description;
        $notes = [];
        foreach (self::STAFF_NOTES as $note) {
            if (str_contains($public, $note)) {
                $public = str_replace($note, '', $public);
                $notes[] = $note;
            }
        }
        $public = trim(preg_replace('/\s{2,}/u', ' ', $public));

        return [$public === '' ? null : $public, $notes ? implode(' ', $notes) : null];
    }

    public function publicDescription(): ?string
    {
        return self::splitDescription($this->description)[0];
    }

    public function staffNote(): ?string
    {
        return self::splitDescription($this->description)[1];
    }
}

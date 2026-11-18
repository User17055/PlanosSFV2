<?php

namespace App\Services\Integrations;

use App\Models\Lead;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\{Http, Log};

/**
 * Aviso de lead novo no celular e no computador pelo ntfy, só nos horários de config('services.leads.alert.hours').
 * Só avisa: não muda o responsável nem a situação do lead.
 * O aviso passa por um servidor de fora, então não leva nome, telefone nem e-mail: só o número do lead, a origem e a campanha.
 */
class LeadAlert
{
    public static function send(Lead $lead): void
    {
        $config = config('services.leads.alert');
        if (empty($config['topic']) || ! self::inWindow(now('America/Sao_Paulo'), $config['hours'] ?? [])) return;
        try {
            Http::timeout(5)->post(rtrim($config['server'], '/'), [
                'topic' => $config['topic'],
                'title' => $lead->source === 'teste' ? 'Lead de teste' : 'Lead novo · '.$lead->entry(),
                'message' => 'Nº '.$lead->id.' · '.$lead->originGroup().' · '.mb_substr($lead->campaignLabel(), 0, 80),
                'tags' => ['bell'],
                'priority' => 4,
                'click' => route('leads.index'),
            ])->throw();
        } catch (\Throwable $e) {
            // Sem o tópico no log. O lead já foi gravado: só o aviso se perde.
            Log::warning('Aviso de lead novo (ntfy) não foi enviado.', ['lead' => $lead->id, 'erro' => class_basename($e)]);
        }
    }

    public static function inWindow(CarbonInterface $at, array $hours): bool
    {
        foreach ($hours[$at->dayOfWeekIso] ?? [] as [$from, $to]) {
            if ($at->hour >= $from && $at->hour < $to) return true;
        }
        return false;
    }
}

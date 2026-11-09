<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** API do Plano São Francisco no site da Tudo pra Pet: activate, deactivate e status, sempre por POST com x-api-key. */
class TudoPraPetClient
{
    public function enabled(): bool
    {
        return (bool) config('integrations.tudoprapet.enabled') && str_starts_with((string) config('integrations.tudoprapet.url'), 'https://')
            && (string) config('integrations.tudoprapet.api_key') !== '';
    }

    public function call(string $action, array $data): array
    {
        if (! $this->enabled()) {
            throw new TudoPraPetException('Integração com a Tudo pra Pet desligada.', 'disabled', false);
        }
        try {
            $response = Http::timeout((int) config('integrations.tudoprapet.timeout', 15))->acceptJson()
                ->withHeaders(['x-api-key' => (string) config('integrations.tudoprapet.api_key')])
                ->post((string) config('integrations.tudoprapet.url'), ['action' => $action] + $data);
        } catch (ConnectionException) {
            throw new TudoPraPetException('Tudo pra Pet indisponível no momento.', 'connection', true);
        }
        if ($response->successful() && $response->json('ok') === true) {
            return $response->json();
        }
        $code = (string) ($response->json('error') ?: 'http_'.$response->status());
        $fields = $response->json('fields');
        $detail = is_array($fields) && $fields ? ' ('.implode(', ', array_is_list($fields) ? $fields : array_keys($fields)).')' : '';
        // Só falha temporária do lado deles vale nova tentativa; dado recusado precisa de correção.
        throw new TudoPraPetException('Tudo pra Pet recusou: '.$code.$detail, $code, $response->serverError() || $response->status() === 429);
    }
}

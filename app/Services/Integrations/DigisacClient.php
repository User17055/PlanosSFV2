<?php

namespace App\Services\Integrations;

use App\Models\DigisacConnection;
use App\Services\Domain\DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** API v1. Nunca repete envios nem devolve respostas brutas/credenciais ao navegador. */
class DigisacClient
{
    public static function baseUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) return null;
        if (isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) return null;
        if (! in_array(rtrim($parts['path'] ?? '', '/'), ['', '/api/v1'], true)) return null;
        $host = strtolower($parts['host']);
        $official = str_ends_with($host, '.digisac.net') || str_ends_with($host, '.digisac.com.br');
        if (! $official && ! in_array($host, config('digisac.allowed_hosts', []), true)) return null;
        return 'https://'.$host;
    }

    public function request(DigisacConnection $connection, string $method, string $path, array $data = []): array
    {
        $base = self::baseUrl($connection->base_url);
        if (! $base) throw new DomainException('DIGISAC_URL', 'O endereço do Digisac não está autorizado.', 422);
        try {
            $request = Http::baseUrl($base.'/api/v1')->withToken($connection->api_token)
                ->acceptJson()->asJson()->connectTimeout(5)->timeout(isset($data['file']) ? 90 : 20)->withoutRedirecting();
            $response = $method === 'GET' ? $request->get($path, $data) : $request->post($path, $data);
        } catch (ConnectionException) {
            throw new DomainException('DIGISAC_TIMEOUT', $method === 'POST'
                ? 'O Digisac não confirmou o envio. Confira a conversa antes de enviar novamente.'
                : 'Não foi possível consultar o Digisac agora. Tente atualizar a conversa.', 503);
        }
        if (! $response->successful()) {
            $message = match ($response->status()) {
                401, 403 => 'O Digisac recusou o acesso. Confira o token e as permissões da conta em Conexão.',
                429 => 'O Digisac atingiu o limite de consultas. Aguarde um pouco e tente novamente.',
                default => $method === 'POST'
                    ? 'O Digisac não confirmou o envio. Confira a conversa e as regras do canal antes de tentar novamente.'
                    : 'O Digisac não conseguiu concluir a consulta. Confira a conexão e tente novamente.',
            };
            throw new DomainException('DIGISAC_RESPONSE', $message, 502);
        }
        $body = $response->json();
        if (! is_array($body)) throw new DomainException('DIGISAC_FORMAT', 'O Digisac retornou uma resposta inesperada. Confira a conexão.', 502);
        return $body;
    }

    /** Conferido na API real em 2026-11-05: GET users/{id} e services/{id} não devolvem o registro, então a busca vai pela listagem e confere o id exato. */
    public function record(DigisacConnection $connection, string $resource, string $id): array
    {
        foreach (self::rows($this->request($connection, 'GET', $resource, ['where[id]' => $id, 'perPage' => 1])) as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === $id) return $row;
        }
        return [];
    }

    /** @return array<string, string> id => nome dos usuários do Digisac que não foram arquivados nem apagados. */
    public function users(DigisacConnection $connection): array
    {
        return collect(self::rows($this->request($connection, 'GET', 'users', ['perPage' => 100])))
            ->filter(fn ($user) => is_array($user) && ! empty($user['id']) && empty($user['archivedAt']) && empty($user['deletedAt']))
            ->mapWithKeys(fn ($user) => [(string) $user['id'] => mb_substr((string) ($user['name'] ?? $user['id']), 0, 120)])->sort()->all();
    }

    public static function rows(array $body): array
    {
        if (array_is_list($body)) return $body;
        $rows = $body['data'] ?? $body['rows'] ?? [];
        if (is_array($rows) && ! array_is_list($rows)) $rows = $rows['rows'] ?? $rows['data'] ?? [];
        return is_array($rows) && array_is_list($rows) ? $rows : [];
    }

    public static function phone(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);
        if (strlen($digits) < 10 || strlen($digits) > 15) return null;
        return strlen($digits) <= 11 ? '55'.$digits : $digits;
    }

    /** Busca só o número do lead na conexão escolhida; a checagem exata evita homônimos e números parciais. */
    public function contact(DigisacConnection $connection, string $phone): ?array
    {
        $body = $this->request($connection, 'GET', 'contacts', [
            'where[serviceId]' => $connection->service_id,
            'where[data.number][$iLike]' => '%'.(str_starts_with($phone, '55') && in_array(strlen($phone), [12, 13], true) ? substr($phone, 2) : $phone).'%',
            'perPage' => 40, 'include[0]' => 'thumbAvatar', 'include[1]' => 'currentTicket',
        ]);
        $matches = array_values(array_filter(self::rows($body), fn ($contact) => is_array($contact)
            && (string) ($contact['serviceId'] ?? '') === $connection->service_id
            && self::phone(data_get($contact, 'data.number')) === $phone
            && ! empty($contact['id']) && empty($contact['archivedAt'])));
        if (count($matches) > 1) throw new DomainException('DIGISAC_CONTACT_AMBIGUOUS', 'Há mais de um contato com esse telefone na conexão. Unifique os contatos no Digisac para continuar.', 409);
        return $matches[0] ?? null;
    }

    public function messages(DigisacConnection $connection, string $contactId, int $page): array
    {
        $body = $this->request($connection, 'GET', 'messages', ['query' => json_encode([
            'where' => ['contactId' => $contactId, 'serviceId' => $connection->service_id, 'visible' => true],
            'include' => ['file'], 'order' => [['timestamp', 'DESC'], ['id', 'DESC']], 'page' => $page, 'perPage' => 40,
        ], JSON_THROW_ON_ERROR)]);
        $rows = self::rows($body);
        $messages = [];
        foreach ($rows as $message) {
            // Mesmo se a API ignorar um filtro, nenhum outro contato/canal ou nota interna sai daqui.
            if (! is_array($message) || (string) ($message['contactId'] ?? '') !== $contactId
                || (string) ($message['serviceId'] ?? '') !== $connection->service_id
                || ! ($message['visible'] ?? false)
                || ! in_array($message['type'] ?? '', ['chat', 'image', 'audio', 'video', 'document', 'ptt', 'sticker'], true)) continue;
            $messages[] = [
                'id' => (string) ($message['id'] ?? ''), 'text' => (string) ($message['text'] ?? ''),
                'type' => $message['type'], 'outgoing' => (bool) ($message['isFromMe'] ?? false),
                'at' => $message['timestamp'] ?? $message['createdAt'] ?? null,
                'sent' => (bool) ($message['sent'] ?? false),
                'file' => ($url = self::fileUrl($message['file'] ?? null))
                    ? ['url' => $url, 'name' => mb_substr((string) data_get($message, 'file.name', ''), 0, 120)] : null,
            ];
        }
        return ['messages' => array_reverse($messages), 'has_more' => count($rows) === 40];
    }

    /** Fotos e arquivos do Digisac vêm em links temporários e assinados do armazenamento dele; só https passa. */
    public static function fileUrl(mixed $file): ?string
    {
        $url = is_array($file) ? ($file['url'] ?? null) : null;
        return is_string($url) && str_starts_with($url, 'https://') && strlen($url) <= 4000 ? $url : null;
    }

    /** $file: ['base64', 'mimetype', 'name']. Com arquivo, a documentação manda sem "type": o Digisac usa o mimetype. */
    public function send(DigisacConnection $connection, ?string $contactId, string $phone, string $text, ?array $file = null): void
    {
        $payload = ['text' => $text] + ($file ? ['file' => $file] : ['type' => 'chat']) + ['userId' => $connection->digisac_user_id, 'origin' => 'user'];
        $payload += $contactId ? ['contactId' => $contactId] : ['number' => $phone, 'serviceId' => $connection->service_id];
        $this->request($connection, 'POST', 'messages', $payload);
    }
}

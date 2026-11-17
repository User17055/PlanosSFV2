<?php

namespace App\Services\Integrations;

use App\Models\{DigisacConnection, Lead};
use App\Services\Domain\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Última mensagem e não lidas de cada lead, para a lista do chat e a bolinha do botão.
 * Cada rodada lê numa consulta só os 100 contatos com mensagem mais recente; lead nunca conferido é buscado um por um, poucos por vez.
 */
class LeadChatSummary
{
    public function __construct(private DigisacClient $client) {}

    /** Direto na tabela: não mexe em updated_at. Sem contato, só registra a consulta. */
    public static function store(array $leadIds, ?array $contact): int
    {
        return DB::table('leads')->whereIn('id', $leadIds)->update(DigisacClient::summary($contact));
    }

    public function run(int $limit = 30): int
    {
        $admin = DigisacConnection::admin();
        if (! $admin) return 0;
        $byPhone = [];
        foreach (Lead::whereNotNull('phone')->pluck('phone', 'id') as $id => $phone) {
            if ($number = DigisacClient::phone($phone)) $byPhone[$number][] = $id;
        }
        try {
            $recent = $this->client->recentContacts($admin);
        } catch (DomainException) {
            return 0; // Digisac fora do ar: fica para o próximo minuto.
        }
        $updated = 0;
        foreach ($recent as $contact) {
            $ids = $byPhone[DigisacClient::phone(data_get($contact, 'data.number')) ?? ''] ?? null;
            if ($ids) $updated += self::store($ids, $contact);
        }
        foreach (Lead::whereNotNull('phone')->whereNull('chat_synced_at')->orderByDesc('id')->limit($limit)->get(['id', 'phone']) as $lead) {
            $phone = DigisacClient::phone($lead->phone);
            try {
                $updated += self::store([$lead->id], $phone ? $this->client->contact($admin, $phone) : null);
            } catch (DomainException $failure) {
                // Contato repetido no Digisac não se resolve sozinho; queda de conexão tenta de novo na próxima rodada.
                if ($failure->errorCode !== 'DIGISAC_CONTACT_AMBIGUOUS') break;
                self::store([$lead->id], null);
            }
        }
        return $updated;
    }
}

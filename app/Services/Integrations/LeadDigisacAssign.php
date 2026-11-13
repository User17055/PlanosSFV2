<?php

namespace App\Services\Integrations;

use App\Models\{DigisacConnection, Lead, User};
use App\Services\Domain\{Audit, DomainException};

/**
 * Lead da fila fica com quem puxou a conversa no Digisac: o primeiro chamado do contato desde o dia do lead diz o atendente,
 * e o "Usuário no Digisac" do cadastro do funcionário (ou a conexão dele no chat) diz quem é no sistema.
 * Só preenche quem está vazio: transferência depois disso não troca o responsável (decisão do usuário em 11/11/2026).
 */
class LeadDigisacAssign
{
    public function __construct(private DigisacClient $client) {}

    public function run(int $limit = 30): int
    {
        $admin = DigisacConnection::admin();
        if (! $admin) return 0;
        $people = User::where('active', true)->whereNotNull('digisac_user_id')->pluck('id', 'digisac_user_id')->all()
            + DigisacConnection::whereNotNull('verified_at')->pluck('user_id', 'digisac_user_id')->all();
        if (! $people) return 0;
        $assigned = 0;
        foreach (Lead::whereNull('handled_by')->whereNotNull('phone')->where('created_at', '>=', now()->subDays(15))->orderBy('id')->limit($limit)->get() as $lead) {
            $phone = DigisacClient::phone($lead->phone);
            if (! $phone) continue;
            try {
                $contact = $this->client->contact($admin, $phone);
                $attendant = $contact ? $this->client->firstAttendant($admin, (string) $contact['id'], $lead->created_at->copy()->setTimezone('America/Sao_Paulo')->startOfDay()) : null;
            } catch (DomainException) {
                continue; // Digisac fora do ar ou contato repetido: fica para a próxima rodada.
            }
            $userId = $attendant ? ($people[$attendant] ?? null) : null;
            // Só quem pode ficar com o lead daquela unidade; quem puxou e não tem acesso a leads não vira responsável.
            if (! $userId || ! Lead::assignees((int) $lead->originating_franchise_id)->contains('id', $userId)) continue;
            if (Lead::whereKey($lead->id)->whereNull('handled_by')->update(['handled_by' => $userId])) {
                // Quem puxou a conversa já está atendendo: "Esperando" vira "Processando" (Finalizado e Sem interesse não mudam).
                $started = Lead::whereKey($lead->id)->where('status', 'new')->update(['status' => 'contacted', 'contacted_at' => $lead->contacted_at ?? now()]);
                Audit::record('lead.assigned_from_digisac', $lead, null, ['handled_by' => $userId] + ($started ? ['status' => 'contacted'] : []));
                $assigned++;
            }
        }
        return $assigned;
    }
}

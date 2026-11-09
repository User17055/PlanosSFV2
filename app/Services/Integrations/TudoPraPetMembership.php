<?php

namespace App\Services\Integrations;

use App\Models\{Customer, Invoice, Subscription};
use App\Services\Domain\Audit;

/**
 * Regra combinada com a Tudo pra Pet (09/11/2026): o tutor fica no grupo "Plano São Francisco" enquanto tiver
 * pelo menos um plano de saúde em vigor e nenhuma mensalidade em aberto depois do último dia do mês de vencimento.
 * Sai quando os planos terminam ou nesse atraso; volta sozinho quando paga.
 */
class TudoPraPetMembership
{
    public function __construct(private TudoPraPetClient $client) {}

    public function desired(Customer $customer): bool
    {
        $inForce = Subscription::where('customer_id', $customer->id)->where('category', 'health_plan')
            ->whereNotNull('activated_at')->whereNotIn('status', Subscription::TERMINAL)->exists();
        if (! $inForce) {
            return false;
        }
        $now = now('America/Sao_Paulo');
        return ! Invoice::where('customer_id', $customer->id)->whereHas('subscription', fn ($q) => $q->where('category', 'health_plan'))
            ->whereNotIn('status', ['paid', 'cancelled', 'refunded'])->whereColumn('paid_cents', '<', 'amount_cents')->whereNotNull('due_at')
            ->get()->contains(fn ($invoice) => $invoice->due_at->copy()->timezone('America/Sao_Paulo')->endOfMonth()->lt($now));
    }

    /** Envia só a mudança (ou tudo, com $force). Falha temporária volta como exceção para a fila tentar de novo. */
    public function sync(Customer $customer, bool $force = false): string
    {
        if (! $this->client->enabled()) {
            return 'disabled';
        }
        $want = $this->desired($customer);
        if (! $force && $want === ($customer->tudoprapet_status === 'active')) {
            return 'unchanged';
        }
        if (! $want && ! $customer->tudoprapet_status && ! $force) {
            return 'unchanged';
        }
        $digits = fn ($value) => preg_replace('/\D/', '', (string) $value);
        try {
            if ($want) {
                $result = $this->client->call('activate', ['name' => $customer->name, 'cpf' => $digits($customer->document),
                    'email' => $customer->email, 'phone' => $digits($customer->phone)]);
            } else {
                try {
                    $result = $this->client->call('deactivate', ['cpf' => $digits($customer->document)]);
                } catch (TudoPraPetException $e) {
                    if ($e->errorCode !== 'member_not_found') {
                        throw $e;
                    }
                    $result = ['note' => 'member_not_found'];
                }
            }
        } catch (TudoPraPetException $e) {
            if ($e->retryable) {
                throw $e;
            }
            $customer->forceFill(['tudoprapet_error' => mb_substr($e->getMessage(), 0, 255), 'tudoprapet_synced_at' => now()])->save();
            Audit::record('tudoprapet.failed', $customer, null, ['action' => $want ? 'activate' : 'deactivate', 'error' => $e->errorCode]);
            return 'failed';
        }
        $customer->forceFill(['tudoprapet_status' => $want ? 'active' : 'inactive', 'tudoprapet_synced_at' => now(), 'tudoprapet_error' => null])->save();
        Audit::record($want ? 'tudoprapet.activated' : 'tudoprapet.deactivated', $customer, null,
            ['account_created' => (bool) ($result['account_created'] ?? false), 'note' => $result['note'] ?? null]);
        return $want ? 'activated' : 'deactivated';
    }
}

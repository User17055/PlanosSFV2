<?php

namespace App\Services\Integrations;

use App\Models\{LegacyPendingItem, PetProductSlot, Subscription, VindiCancellation};
use App\Services\Domain\Audit;
use App\Services\Reports\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Só lê a Vindi: guarda um item por pet de cada assinatura cancelada lá e encerra aqui o contrato da base antiga que estava nela.
 * Nada é enviado à Vindi.
 */
class VindiCancellationSync
{
    /** Outra assinatura do mesmo tipo começando até 10 dias depois do cancelamento ainda conta como continuidade (refeita). */
    private const REPLACED_WITHIN_DAYS = 10;

    public function __construct(private VindiClient $client) {}

    public function run(): array
    {
        $subscriptions = [];
        for ($page = 1; ; $page++) {
            $rows = $this->client->page('subscriptions', $page, 'status:canceled');
            array_push($subscriptions, ...$rows);
            if (count($rows) < VindiClient::PER_PAGE) {
                break;
            }
            usleep(500000); // dentro do limite de 120 consultas por minuto, com folga para a conciliação
        }
        $intervals = $this->intervalsByCustomer($subscriptions);
        $report = ['assinaturas' => count($subscriptions), 'itens' => 0, 'refeitas' => 0, 'contratos_encerrados' => 0, 'pets_para_escolher_resolvidos' => 0];
        foreach ($subscriptions as $s) {
            $plan = DashboardService::normalize(preg_replace('/[\x{2800}\s]+$/u', '', (string) data_get($s, 'plan.name')));
            if (in_array($plan, ['teste', 'servicos'], true) || empty($s['cancel_at'])) {
                continue;
            }
            $cancelled = CarbonImmutable::parse($s['cancel_at']);
            $customer = (string) data_get($s, 'customer.id');
            // Não é saída quando o cliente seguiu com outra assinatura do mesmo tipo (duplicada, campanha, troca de cartão).
            $replaced = collect($intervals[$customer] ?? [])->contains(fn ($i) => $i['id'] !== (string) $s['id'] && $i['category'] === $this->category($s)
                && $i['start']->lte($cancelled->addDays(self::REPLACED_WITHIN_DAYS)) && (! $i['stop'] || $i['stop']->gt($cancelled)));
            foreach ($s['product_items'] ?? [] as $item) {
                $name = $this->itemName($item);
                if ($name === null) {
                    continue;
                }
                VindiCancellation::updateOrCreate(['vindi_product_item_id' => (string) $item['id']], [
                    'vindi_subscription_id' => (string) $s['id'], 'vindi_customer_id' => $customer ?: null, 'product_name' => $name,
                    'category' => str_starts_with(DashboardService::normalize($name), 'pacote') ? 'package' : 'health_plan',
                    'price_cents' => VindiReconciler::decimalToCents(data_get($item, 'pricing_schema.price')),
                    'started_at' => ! empty($s['start_at']) ? CarbonImmutable::parse($s['start_at'])->utc() : null,
                    'cancelled_at' => $cancelled->utc(), 'replaced' => $replaced,
                ]);
                $report['itens']++;
                $report['refeitas'] += (int) $replaced;
            }
            $report['contratos_encerrados'] += $this->closeLocal((string) $s['id'], $cancelled);
            $report['pets_para_escolher_resolvidos'] += LegacyPendingItem::whereNull('resolved_at')->where('vindi_subscription_id', (string) $s['id'])
                ->update(['resolution' => 'cancelled_in_vindi', 'resolved_at' => now()]);
        }

        return $report;
    }

    /** Item que é pet (plano ou pacote): sem "GASTOS EXTRAS" e "DIREITOS DE USO DE MARCA". */
    private function itemName(array $item): ?string
    {
        $name = trim(preg_replace('/[\x{2800}\s]+$/u', '', (string) data_get($item, 'product.name')));

        return $name === '' || preg_match('/gastos extras|direitos de uso/', DashboardService::normalize($name)) ? null : $name;
    }

    private function category(array $subscription): string
    {
        $names = array_filter(array_map(fn ($item) => $this->itemName($item), $subscription['product_items'] ?? []));

        return $names && collect($names)->every(fn ($name) => str_starts_with(DashboardService::normalize($name), 'pacote')) ? 'package' : 'health_plan';
    }

    /** Período de cada assinatura por cliente da Vindi: as canceladas (datas da Vindi) e as que estão aqui. */
    private function intervalsByCustomer(array $cancelled): array
    {
        $intervals = [];
        foreach ($cancelled as $s) {
            if (! empty($s['start_at'])) {
                $intervals[(string) data_get($s, 'customer.id')][] = ['id' => (string) $s['id'], 'category' => $this->category($s), 'start' => CarbonImmutable::parse($s['start_at']),
                    'stop' => ! empty($s['cancel_at']) ? CarbonImmutable::parse($s['cancel_at']) : null];
            }
        }
        $known = collect($cancelled)->pluck('id')->map(fn ($id) => (string) $id)->flip();
        Subscription::whereNotNull('subscriptions.vindi_subscription_id')->whereNotNull('subscriptions.effective_start_at')
            ->join('customers', 'customers.id', '=', 'subscriptions.customer_id')->whereNotNull('customers.vindi_customer_id')
            ->get(['subscriptions.vindi_subscription_id', 'subscriptions.category', 'subscriptions.status', 'subscriptions.effective_start_at', 'subscriptions.cancelled_at', 'subscriptions.ends_at', 'customers.vindi_customer_id'])
            ->reject(fn ($row) => isset($known[(string) $row->vindi_subscription_id]))
            ->each(function ($row) use (&$intervals) {
                $stop = $row->cancelled_at ?? (in_array($row->status, Subscription::TERMINAL, true) ? $row->ends_at : null);
                $intervals[(string) $row->vindi_customer_id][] = ['id' => (string) $row->vindi_subscription_id, 'category' => $row->category,
                    'start' => CarbonImmutable::parse($row->effective_start_at), 'stop' => $stop ? CarbonImmutable::parse($stop) : null];
            });

        return $intervals;
    }

    /** Contrato da base antiga ainda ativo aqui numa assinatura que a Vindi cancelou: encerra na data da Vindi, sem e-mail e sem avisar a Vindi. */
    private function closeLocal(string $vindiSubscriptionId, CarbonImmutable $at): int
    {
        $closed = 0;
        foreach (Subscription::where('vindi_subscription_id', $vindiSubscriptionId)->whereNotNull('imported_at')->whereNotIn('status', Subscription::TERMINAL)->pluck('id') as $id) {
            DB::transaction(function () use ($id, $at, &$closed) {
                $s = Subscription::lockForUpdate()->find($id);
                if (! $s || $s->isTerminal()) {
                    return;
                }
                $s->update(['status' => 'cancelled', 'cancelled_at' => $at->utc(), 'ends_at' => $at->utc(), 'cancellation_reason' => 'Assinatura cancelada na Vindi']);
                PetProductSlot::where('subscription_id', $s->id)->update(['state' => 'released', 'released_at' => now(), 'expires_at' => null]);
                Audit::record('legacy_contract_cancelled_in_vindi', $s, null, ['vindi_subscription_id' => $s->vindi_subscription_id, 'cancelled_at' => $at->toIso8601String()]);
                $closed++;
            }, 3);
        }

        return $closed;
    }
}

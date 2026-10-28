<?php

namespace App\Services\Integrations;

use App\Jobs\StartVindiRecurringJob;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Domain\FinancialService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class VindiReconciler
{
    public function __construct(private VindiClient $client, private FinancialService $financial) {}

    /** The webhook's paid flag and amounts are never used as financial evidence. */
    public function reconcileBill(string $externalId, ?string $account = null): Invoice
    {
        $account ??= (string) config('integrations.vindi.provider_account', 'central');
        try {
            $invoice = $this->applyCurrentBill($externalId, $account);
        } catch (\Throwable $exception) {
            $this->markUncertain($externalId, $account);
            throw $exception;
        }
        $this->startRecurringAfterEntry($invoice);
        return $invoice;
    }

    /** A paid entry validates the plan; the monthly subscription is then opened in the queue, outside this transaction. */
    private function startRecurringAfterEntry(Invoice $invoice): void
    {
        $subscription = $invoice->subscription;
        if ($invoice->is_initial && $invoice->status === 'paid' && ! $subscription->vindi_subscription_id && ! $subscription->isTerminal()
            && $subscription->productVersion->billing_cycle === 'monthly') {
            StartVindiRecurringJob::dispatch($subscription->id)->afterCommit();
        }
    }

    private function applyCurrentBill(string $externalId, string $account): Invoice
    {
        if ($account !== config('integrations.vindi.provider_account', 'central')) {
            throw new IntegrationPendingException('A conta da fatura difere da credencial configurada.');
        }
        $bill = $this->client->bill($externalId); // External IO occurs outside database transactions.
        if ((string) ($bill['id'] ?? '') !== $externalId || empty($bill['updated_at'])) {
            throw new IntegrationPendingException('A API não devolveu uma fatura verificável.');
        }
        $invoice = Invoice::where('provider_account', $account)->where('external_id', $externalId)->first();
        if (empty(data_get($bill, 'subscription.id'))) {
            // A standalone bill is only the entry installment already linked at checkout to the tutor's Vindi customer.
            $subscription = $invoice?->is_initial ? $invoice->subscription : null;
            if (! $subscription || (string) data_get($bill, 'customer.id') !== (string) $subscription->customer->vindi_customer_id) {
                throw new IntegrationPendingException('Fatura sem vínculo financeiro local inequívoco; conciliação manual necessária.');
            }
        } else {
            $subscription = Subscription::where('provider_account', $account)
                ->where('vindi_subscription_id', (string) data_get($bill, 'subscription.id'))->first();
            if (! $subscription) {
                throw new IntegrationPendingException('Fatura sem vínculo financeiro local inequívoco; conciliação manual necessária.');
            }
        }
        $amount = self::decimalToCents($bill['amount'] ?? null);
        if (! $invoice && Invoice::where('subscription_id', $subscription->id)->where('is_initial', true)->whereNull('external_id')->exists()) {
            // The commercial setup must attach the first provider bill to the
            // existing initial obligation. Creating another invoice here would
            // leave the original pending and can duplicate the obligation.
            throw new IntegrationPendingException('A cobrança inicial local ainda não possui vínculo Vindi confirmado. Vincule a fatura correta em conciliação antes de importar outras cobranças.');
        }
        if ($invoice && $invoice->subscription_id !== $subscription->id) {
            throw new IntegrationPendingException('O vínculo da fatura diverge da assinatura; nenhuma alteração aplicada.');
        }
        if ($invoice && $invoice->amount_cents !== $amount) {
            throw new IntegrationPendingException('O valor atual da Vindi diverge do valor local; revisão financeira necessária.');
        }
        $status = (string) ($bill['status'] ?? '');
        if (collect($bill['charges'] ?? [])->contains(fn ($charge) => in_array(data_get($charge, 'last_transaction.transaction_type'), ['refund', 'chargeback'], true))) {
            throw new IntegrationPendingException('Estorno ou contestação detectado. Interpretar a obrigação e confirmar o saldo em conciliação financeira.');
        }
        $mappedStatus = match ($status) {
            'paid' => 'paid', 'canceled' => 'cancelled', 'pending', 'scheduled' => 'pending',
            default => throw new IntegrationPendingException('Estado Vindi não homologado: '.$status),
        };
        $paidCharges = collect($bill['charges'] ?? [])->where('status', 'paid');
        if ($status !== 'paid' && $paidCharges->isNotEmpty()) {
            throw new IntegrationPendingException('Recebimento parcial ou divergente exige interpretação financeira antes da liberação.');
        }
        $paidAt = $paidCharges->pluck('paid_at')->filter()->sortDesc()->first();
        if ($status === 'paid' && ! $paidAt) {
            throw new IntegrationPendingException('Fatura paga sem data de recebimento verificável; revisão necessária.');
        }
        $dueAt = $this->instant((string) ($bill['due_at'] ?? ''));
        if (! $invoice) {
            $invoice = DB::transaction(function () use ($subscription, $account, $externalId, $amount, $bill, $dueAt) {
                // Match the domain lock order even when importing a recurring bill.
                DB::table('customers')->where('id', $subscription->customer_id)->lockForUpdate()->first();
                DB::table('pets')->where('id', $subscription->pet_id)->lockForUpdate()->first();
                $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
                return Invoice::firstOrCreate(['provider_account' => $account, 'external_id' => $externalId], [
                    'subscription_id' => $locked->id, 'customer_id' => $locked->customer_id,
                    'originating_franchise_id' => $locked->originating_franchise_id,
                    'amount_cents' => $amount, 'due_at' => $dueAt, 'status' => 'pending',
                    'is_initial' => ! Invoice::where('subscription_id', $locked->id)->exists(),
                    'competence_start' => data_get($bill, 'period.start_at') ? $this->instant(data_get($bill, 'period.start_at')) : null,
                    'competence_end' => data_get($bill, 'period.end_at') ? $this->instant(data_get($bill, 'period.end_at')) : null,
                    'payment_url' => $this->safePaymentUrl($bill['url'] ?? null),
                ]);
            }, 3);
        }
        return $this->financial->applyVerifiedInvoice($invoice, [
            'status' => $mappedStatus, 'paid_cents' => $status === 'paid' ? $amount : 0,
            'paid_at' => $paidAt ? $this->instant($paidAt) : null, 'provider_status' => $status,
            'provider_updated_at' => $this->instant($bill['updated_at']),
            'reference' => 'vindi:'.$account.':bill:'.$externalId, 'source' => 'vindi_api',
        ]);
    }

    public function reconcileSubscription(Subscription $subscription): int
    {
        if ($subscription->provider_account !== config('integrations.vindi.provider_account', 'central')) {
            throw new IntegrationPendingException('Conta Vindi não configurada nesta instância.');
        }
        $count = 0;
        // The entry installment is a standalone bill, so it is not listed among the subscription's bills.
        $entry = Invoice::where('subscription_id', $subscription->id)->where('is_initial', true)->whereNotNull('external_id')->first();
        if ($entry && $entry->provider_account === $subscription->provider_account && $entry->status === 'pending') {
            $bill = $this->client->bill((string) $entry->external_id);
            if (empty(data_get($bill, 'subscription.id'))) {
                $entry = $this->reconcileBill((string) $entry->external_id, $subscription->provider_account);
                $count++;
            }
        } elseif ($entry) {
            // Retries the monthly subscription when an earlier attempt after the paid entry did not finish.
            $this->startRecurringAfterEntry($entry);
        }
        $subscription = $subscription->fresh();
        if (! $subscription->vindi_subscription_id) {
            return $count;
        }
        for ($page = 1; $page <= 100; $page++) {
            $bills = $this->client->billsForSubscription((string) $subscription->vindi_subscription_id, $page);
            foreach ($bills as $bill) {
                $this->reconcileBill((string) $bill['id'], $subscription->provider_account);
                $count++;
            }
            if (count($bills) < 50) {
                return $count;
            }
        }
        throw new IntegrationPendingException('Limite de paginação de conciliação atingido. Revisar histórico antes de continuar.');
    }

    public static function decimalToCents(mixed $value): int
    {
        if (! is_scalar($value) || ! preg_match('/^(\d+)(?:\.(\d{1,2})0*)?$/D', (string) $value, $matches)) {
            throw new IntegrationPendingException('Valor financeiro da API inválido.');
        }
        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function instant(string $value): CarbonImmutable
    {
        if ($value === '') {
            throw new IntegrationPendingException('Data financeira ausente.');
        }
        $date = CarbonImmutable::parse($value, 'America/Sao_Paulo');
        // A civil due date expires at local end of day, not UTC midnight.
        return (strlen($value) === 10 ? $date->endOfDay() : $date)->utc();
    }

    private function safePaymentUrl(?string $url): ?string
    {
        return $url && filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://') ? $url : null;
    }

    private function markUncertain(string $externalId, string $account): void
    {
        $invoice = Invoice::where('provider_account', $account)->where('external_id', $externalId)->first();
        if (! $invoice) {
            return;
        }
        DB::transaction(function () use ($invoice) {
            DB::table('customers')->where('id', $invoice->customer_id)->lockForUpdate()->first();
            $subscription = Subscription::findOrFail($invoice->subscription_id);
            DB::table('pets')->where('id', $subscription->pet_id)->lockForUpdate()->first();
            DB::table('subscriptions')->where('id', $subscription->id)->lockForUpdate()->first();
            Invoice::whereKey($invoice->id)->update(['reconciliation_status' => 'uncertain']);
            DB::table('customers')->where('id', $invoice->customer_id)->update(['financial_verification_status' => 'pending']);
            $subscription->update(['financial_verification_status' => 'pending']);
        }, 3);
    }
}

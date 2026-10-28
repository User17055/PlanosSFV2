<?php

namespace App\Jobs;

use App\Models\IntegrationEvent;
use App\Models\Subscription;
use App\Services\Integrations\IntegrationPendingException;
use App\Services\Integrations\VindiCheckoutService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Opens the open-ended Vindi subscription once the entry (first installment) is confirmed as paid. */
class StartVindiRecurringJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;
    public int $uniqueFor = 300;

    public function __construct(public int $subscriptionId)
    {
        $this->onQueue('vindi');
    }

    public function uniqueId(): string
    {
        return 'start-vindi-recurring-'.$this->subscriptionId;
    }

    public function backoff(): array
    {
        return [120, 600];
    }

    public function handle(VindiCheckoutService $checkout): void
    {
        $subscription = Subscription::findOrFail($this->subscriptionId);
        try {
            $checkout->startRecurring($subscription);
        } catch (\Throwable $exception) {
            IntegrationEvent::updateOrCreate([
                'idempotency_key' => 'vindi:'.$subscription->provider_account.':start-recurring:'.$subscription->id.':'.now()->format('YmdH'),
            ], [
                'provider' => 'vindi', 'provider_account' => $subscription->provider_account,
                'event_type' => 'recurring_start_failed', 'entity_type' => 'local_subscription', 'entity_id' => $subscription->id,
                'status' => $exception instanceof IntegrationPendingException ? 'manual' : 'error',
                'error' => $exception instanceof IntegrationPendingException ? $exception->getMessage() : 'Falha ao criar a assinatura mensal na Vindi. A conferência periódica tentará novamente.',
                'received_at' => now(), 'payload' => ['subscription_id' => $subscription->id],
            ]);
            if (! $exception instanceof IntegrationPendingException) {
                throw new \RuntimeException('A criação da assinatura mensal na Vindi falhou. Verificar a pendência registrada no painel de integrações.');
            }
        }
    }
}

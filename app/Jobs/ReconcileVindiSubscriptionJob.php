<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Models\IntegrationEvent;
use App\Services\Integrations\IntegrationPendingException;
use App\Services\Integrations\VindiReconciler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileVindiSubscriptionJob implements ShouldQueue, ShouldBeUnique
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
        return 'reconcile-vindi-'.$this->subscriptionId;
    }

    public function backoff(): array
    {
        return [120, 600];
    }

    public function handle(VindiReconciler $reconciler): void
    {
        $subscription = Subscription::findOrFail($this->subscriptionId);
        try {
            $reconciler->reconcileSubscription($subscription);
        } catch (\Throwable $exception) {
            IntegrationEvent::updateOrCreate([
                'idempotency_key' => 'vindi:'.$subscription->provider_account.':reconcile:'.$subscription->id.':'.now()->format('YmdH'),
            ], [
                'provider' => 'vindi', 'provider_account' => $subscription->provider_account,
                'event_type' => 'reconciliation_failed', 'entity_type' => 'local_subscription', 'entity_id' => $subscription->id,
                'status' => $exception instanceof IntegrationPendingException ? 'manual' : 'error',
                'error' => $exception instanceof IntegrationPendingException ? $exception->getMessage() : 'Falha temporária ao consultar a Vindi. Verificar rede e credenciais.',
                'received_at' => now(), 'payload' => ['subscription_id' => $subscription->id],
            ]);
            if (! $exception instanceof IntegrationPendingException) {
                // Do not serialize raw provider response bodies in failed_jobs.
                throw new \RuntimeException('A conciliação Vindi falhou. Verificar a pendência registrada no painel de integrações.');
            }
        }
    }
}

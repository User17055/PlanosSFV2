<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Models\IntegrationEvent;
use App\Services\Integrations\IntegrationPendingException;
use App\Services\Integrations\VindiClient;
use App\Services\Integrations\VindiReconciler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

class ReconcileVindiSubscriptionJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    // Esperar a vez no limite por minuto não conta como falha; só erro de verdade conta.
    public int $maxExceptions = 3;
    public int $timeout = 120;
    public int $uniqueFor = 1500;

    public function __construct(public int $subscriptionId)
    {
        $this->onQueue('vindi');
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(25);
    }

    public function middleware(): array
    {
        return [new RateLimited('vindi')];
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
                Log::warning('Vindi: conciliação não concluída nesta tentativa.', ['assinatura' => $subscription->id] + VindiClient::failureReason($exception));
                // Do not serialize raw provider response bodies in failed_jobs.
                throw new \RuntimeException('A conciliação Vindi falhou. Verificar a pendência registrada no painel de integrações.');
            }
        }
    }
}

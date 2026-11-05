<?php

namespace App\Jobs;

use App\Models\IntegrationEvent;
use App\Models\Subscription;
use App\Services\Integrations\IntegrationPendingException;
use App\Services\Integrations\VindiClient;
use App\Services\Integrations\VindiReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessVindiWebhookJob implements ShouldQueue
{
    use Queueable;

    // Esperar a vez no limite por minuto não é falha: só erro de verdade conta para desistir. Em um lote grande, os avisos aguardam em vez de esgotar as tentativas.
    public int $maxExceptions = 5;
    public int $timeout = 90;

    public function __construct(public int $eventId)
    {
        $this->onQueue('vindi');
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function middleware(): array
    {
        return [new RateLimited('vindi'), (new WithoutOverlapping('vindi-event-'.$this->eventId))->releaseAfter(30)->expireAfter(180)];
    }

    public function handle(VindiReconciler $reconciler): void
    {
        $event = IntegrationEvent::findOrFail($this->eventId);
        if (in_array($event->status, ['synced', 'manual'], true)) {
            return;
        }
        $event->update(['status' => 'processing', 'attempts' => $event->attempts + 1, 'error' => null]);
        try {
            if ($event->entity_type === 'invoice') {
                $reconciler->reconcileBill((string) $event->entity_id, $event->provider_account);
            } elseif (in_array($event->entity_type, ['subscription', 'local_subscription'], true)) {
                $subscription = Subscription::where('provider_account', $event->provider_account)
                    ->when($event->entity_type === 'local_subscription', fn ($q) => $q->whereKey($event->entity_id),
                        fn ($q) => $q->where('vindi_subscription_id', $event->entity_id))->first();
                if (! $subscription) {
                    throw new IntegrationPendingException('Assinatura externa sem vínculo local; revisão necessária.');
                }
                $reconciler->reconcileSubscription($subscription);
                if ($event->event_type === 'subscription_canceled') {
                    throw new IntegrationPendingException('Cancelamento no provedor detectado. Conferir encerramento e cobranças locais; contrato não foi apagado.');
                }
            } else {
                throw new IntegrationPendingException('Tipo de evento sem automação homologada; revisão manual necessária.');
            }
            $event->update(['status' => 'synced', 'processed_at' => now()]);
        } catch (IntegrationPendingException $exception) {
            $event->update(['status' => 'manual', 'error' => $exception->getMessage(), 'processed_at' => now()]);
        } catch (Throwable $exception) {
            Log::warning('Vindi: aviso não processado nesta tentativa.', ['evento' => $event->id] + VindiClient::failureReason($exception));
            // No raw HTTP response or secrets in the operator-visible error.
            $event->update(['status' => 'error', 'error' => 'Falha temporária na consulta Vindi. Aguardando nova tentativa.']);
            throw new \RuntimeException('Consulta Vindi indisponível. Verificar credencial/rede e consultar a fila de conciliação.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        IntegrationEvent::whereKey($this->eventId)->update([
            'status' => 'error', 'error' => 'Limite de tentativas atingido. Verificar credencial, rede e conciliar a fatura antes de reprocessar.',
        ]);
    }
}

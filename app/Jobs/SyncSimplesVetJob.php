<?php

namespace App\Jobs;

use App\Models\IntegrationEvent;
use App\Models\Subscription;
use App\Services\Domain\Audit;
use App\Services\Integrations\IntegrationPendingException;
use App\Services\Integrations\SimplesVetAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Roda o robô do SimplesVet para um contrato. O que precisa de gente vira pendência na tela de Integrações; falha passageira é repetida. */
class SyncSimplesVetJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;
    public int $timeout = 240;

    public function __construct(public int $subscriptionId)
    {
        $this->onQueue('simplesvet');
    }

    public function backoff(): array
    {
        return [300, 1800, 7200];
    }

    public function handle(SimplesVetAdapter $adapter): void
    {
        $subscription = Subscription::find($this->subscriptionId);
        if (! $subscription) {
            return;
        }
        try {
            $result = $adapter->synchronize($subscription);
        } catch (IntegrationPendingException $exception) {
            $this->pending($subscription, 'manual', $exception->getMessage());
            return;
        }
        // Pendências anteriores deste contrato ficam resolvidas.
        IntegrationEvent::where('provider', 'simplesvet')->where('entity_type', 'subscription')->where('entity_id', $subscription->id)
            ->whereIn('status', ['pending', 'manual', 'error'])->update(['status' => 'synced', 'processed_at' => now(), 'error' => null]);
        Audit::record('simplesvet.synced', $subscription, null, ['actions' => $result['actions'] ?? []]);
    }

    public function failed(\Throwable $exception): void
    {
        if ($subscription = Subscription::find($this->subscriptionId)) {
            $this->pending($subscription, 'error', 'O robô do SimplesVet não concluiu depois de várias tentativas. Tente de novo pela tela de Integrações.');
        }
    }

    private function pending(Subscription $subscription, string $status, string $message): void
    {
        IntegrationEvent::updateOrCreate(['idempotency_key' => 'simplesvet:robo:'.$subscription->id.':'.$subscription->status], [
            'provider' => 'simplesvet', 'event_type' => 'subscription.'.$subscription->status, 'entity_type' => 'subscription', 'entity_id' => $subscription->id,
            'status' => $status, 'error' => mb_substr($message, 0, 1000), 'payload' => ['subscription_id' => $subscription->id],
            'received_at' => now(), 'processed_at' => now(),
        ]);
    }
}

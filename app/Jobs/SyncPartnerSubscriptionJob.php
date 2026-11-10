<?php

namespace App\Jobs;

use App\Models\IntegrationEvent;
use App\Models\Subscription;
use App\Services\Integrations\IntegrationPendingException;
use App\Services\Integrations\SimplesVetAdapter;
use App\Services\Integrations\TudoPraPetAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Each destination has a separate queue. Currently both require documented manual work. */
class SyncPartnerSubscriptionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    // O robô do SimplesVet leva até uns 3 minutos.
    public int $timeout = 240;

    public function __construct(public int $eventId)
    {
        $provider = IntegrationEvent::whereKey($eventId)->value('provider');
        $this->onQueue(in_array($provider, ['simplesvet', 'tudoprapet'], true) ? $provider : 'partners');
    }

    public function handle(SimplesVetAdapter $simplesvet, TudoPraPetAdapter $tudoprapet): void
    {
        $event = IntegrationEvent::findOrFail($this->eventId);
        if (in_array($event->status, ['synced', 'manual'], true)) {
            return;
        }
        $event->update(['status' => 'processing', 'attempts' => $event->attempts + 1]);
        $adapter = match ($event->provider) {
            'simplesvet' => $simplesvet, 'tudoprapet' => $tudoprapet,
            default => throw new \InvalidArgumentException('Parceiro não suportado.'),
        };
        try {
            $adapter->synchronize(Subscription::findOrFail($event->entity_id));
            $event->update(['status' => 'synced', 'processed_at' => now(), 'error' => null]);
        } catch (IntegrationPendingException $exception) {
            $event->update(['status' => 'manual', 'processed_at' => now(), 'error' => $exception->getMessage()]);
        }
    }
}

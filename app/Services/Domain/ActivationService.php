<?php

namespace App\Services\Domain;

use App\Models\Customer;
use App\Models\IntegrationEvent;
use App\Models\Pet;
use App\Models\PetProductSlot;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

class ActivationService
{
    public function __construct(private EligibilityService $eligibility) {}

    public function activate(Subscription $subscription): array
    {
        return DB::transaction(function () use ($subscription) {
            Customer::lockForUpdate()->findOrFail($subscription->customer_id);
            Pet::lockForUpdate()->findOrFail($subscription->pet_id);
            $s = Subscription::lockForUpdate()->findOrFail($subscription->id);
            $issues = $this->eligibility->activationImpediments($s);
            if ($s->isTerminal()) {
                return ['eligible' => false, 'code' => 'SUBSCRIPTION_ENDED', 'message' => 'Contrato encerrado.', 'impediments' => $issues];
            }
            if ($issues) {
                if ($s->status === 'active') {
                    $s->update(['status' => 'suspended']);
                    Audit::record('subscription_suspended', $s, null, ['impediments' => $issues]);
                    $this->outbox($s, 'suspended');
                }

                return ['eligible' => false, 'code' => $issues[0]['code'], 'message' => $issues[0]['message'], 'impediments' => $issues];
            }
            $first = ! $s->activated_at;
            $changes = ['status' => $s->status === 'scheduled_cancellation' ? 'scheduled_cancellation' : 'active'];
            if ($first) {
                $changes['activated_at'] = now();
                $changes['effective_start_at'] = now();
                if ($s->productVersion->duration_months && ! $s->ends_at) {
                    $changes['ends_at'] = now('America/Sao_Paulo')->addMonthsNoOverflow($s->productVersion->duration_months)->utc();
                }
            }
            if ($first || $s->isDirty() || $s->status !== $changes['status']) {
                $old = $s->status;
                $s->update($changes);
                PetProductSlot::where('subscription_id', $s->id)->update(['state' => 'occupied']);
                Audit::record($first ? 'subscription_activated' : 'subscription_regularized', $s, null, ['from' => $old, 'to' => $s->status]);
                $this->outbox($s, $first ? 'activated' : 'regularized');
            }

            return ['eligible' => true, 'code' => 'ELIGIBLE', 'message' => 'Assinatura ativa.', 'impediments' => []];
        }, 3);
    }

    private function outbox(Subscription $s, string $event): void
    {
        foreach (['simplesvet', 'tudoprapet'] as $provider) {
            if ($provider === 'tudoprapet' && app(\App\Services\Integrations\TudoPraPetClient::class)->enabled()) {
                \App\Jobs\SyncTudoPraPetJob::dispatch($s->customer_id)->afterCommit();
                continue;
            }
            IntegrationEvent::firstOrCreate(['idempotency_key' => "$provider:subscription:$s->id:$event:".$s->updated_at->format('YmdHis.u')],
                ['provider' => $provider, 'event_type' => 'subscription.'.$event, 'entity_type' => 'subscription', 'entity_id' => $s->id, 'status' => 'manual', 'payload' => ['subscription_id' => $s->id, 'status' => $s->status], 'received_at' => now()]);
        }
    }
}

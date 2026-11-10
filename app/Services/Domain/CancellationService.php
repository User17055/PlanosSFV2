<?php

namespace App\Services\Domain;

use App\Models\BenefitUsage;
use App\Models\Customer;
use App\Models\IntegrationEvent;
use App\Models\Pet;
use App\Models\PetProductSlot;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CancellationService
{
    public function finalizeDue(): int
    {
        $count = 0;
        foreach (Subscription::whereNotIn('status', Subscription::TERMINAL)->whereNotNull('ends_at')->where('ends_at', '<=', now())->pluck('id') as $id) {
            $changed = DB::transaction(function () use ($id) {
                $reference = Subscription::findOrFail($id);
                Customer::lockForUpdate()->findOrFail($reference->customer_id);
                Pet::lockForUpdate()->findOrFail($reference->pet_id);
                $s = Subscription::lockForUpdate()->findOrFail($id);
                if ($s->isTerminal() || ! $s->ends_at || $s->ends_at->isFuture()) {
                    return false;
                }
                $s->update(['status' => 'ended', 'cancelled_at' => now()]);
                PetProductSlot::where('subscription_id', $s->id)->update(['state' => 'released', 'released_at' => now(), 'expires_at' => null]);
                BenefitUsage::where('subscription_id', $s->id)->where('status', 'reserved')->update(['status' => 'cancelled', 'reason' => 'Vigência encerrada.']);
                Audit::record('subscription_ended', $s, null, ['effective_at' => $s->ends_at->toIso8601String()]);
                foreach (['vindi', 'simplesvet', 'tudoprapet'] as $provider) {
                    if ($provider === 'tudoprapet' && app(\App\Services\Integrations\TudoPraPetClient::class)->enabled()) {
                        \App\Jobs\SyncTudoPraPetJob::dispatch($s->customer_id)->afterCommit();
                        continue;
                    }
                    if ($provider === 'simplesvet' && app(\App\Services\Integrations\SimplesVetAdapter::class)->enabled()) {
                        \App\Jobs\SyncSimplesVetJob::dispatch($s->id)->afterCommit();
                        continue;
                    }
                    IntegrationEvent::firstOrCreate(['idempotency_key' => "$provider:ended:$s->id"],
                        ['provider' => $provider, 'event_type' => 'subscription.ended', 'entity_type' => 'subscription', 'entity_id' => $s->id, 'status' => 'manual', 'payload' => ['subscription_id' => $s->id], 'received_at' => now()]);
                }

                return true;
            }, 3);
            if ($changed) {
                $count++;
            }
        }

        return $count;
    }

    public function cancel(Subscription $subscription, User $actor, string $reason, ?string $effectiveAt = null): Subscription
    {
        if (! $actor->active || ! in_array($actor->role, ['central_admin', 'manager'], true) || ! $actor->hasFranchise($subscription->originating_franchise_id)) {
            throw new DomainException('FORBIDDEN', 'Seu perfil não pode encerrar este vínculo.', 403);
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new DomainException('REASON_REQUIRED', 'Informe o motivo do encerramento.', 422);
        }

        return DB::transaction(function () use ($subscription, $actor, $reason, $effectiveAt) {
            Customer::lockForUpdate()->findOrFail($subscription->customer_id);
            Pet::lockForUpdate()->findOrFail($subscription->pet_id);
            $s = Subscription::lockForUpdate()->findOrFail($subscription->id);
            if ($s->isTerminal()) {
                return $s;
            }
            $at = $effectiveAt ? Carbon::parse($effectiveAt)->utc() : now();
            if ($s->ends_at && $at->gt($s->ends_at)) {
                $at = $s->ends_at;
            }
            if ($at->isFuture()) {
                $s->update(['status' => 'scheduled_cancellation', 'ends_at' => $at, 'cancellation_reason' => $reason]);
            } else {
                $s->update(['status' => 'cancelled', 'cancelled_at' => now(), 'ends_at' => now(), 'cancellation_reason' => $reason]);
                PetProductSlot::where('subscription_id', $s->id)->update(['state' => 'released', 'released_at' => now(), 'expires_at' => null]);
                BenefitUsage::where('subscription_id', $s->id)->where('status', 'reserved')->update(['status' => 'cancelled', 'reason' => 'Contrato encerrado: '.$reason]);
            }
            Audit::record('cancellation_'.($at->isFuture() ? 'scheduled' : 'executed'), $s, $actor, ['effective_at' => $at->toIso8601String()], $reason);
            // Com o robô ligado, quem tira as marcas do SimplesVet é o fim do contrato, não o pedido.
            foreach (array_diff(['vindi', 'simplesvet', 'tudoprapet'], app(\App\Services\Integrations\SimplesVetAdapter::class)->enabled() ? ['simplesvet'] : []) as $provider) {
                IntegrationEvent::firstOrCreate(['idempotency_key' => "$provider:cancellation:$s->id:".$at->format('YmdHis')],
                    ['provider' => $provider, 'event_type' => 'subscription.cancellation_requested', 'entity_type' => 'subscription', 'entity_id' => $s->id, 'status' => 'manual', 'payload' => ['subscription_id' => $s->id, 'effective_at' => $at->toIso8601String()], 'received_at' => now()]);
            }

            return $s->fresh();
        }, 3);
    }
}

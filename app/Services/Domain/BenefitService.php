<?php

namespace App\Services\Domain;

use App\Models\BenefitRule;
use App\Models\BenefitUsage;
use App\Models\Customer;
use App\Models\Pet;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BenefitService
{
    public function __construct(private EligibilityService $eligibility) {}

    public function reserve(Subscription $subscription, BenefitRule $rule, User $actor, int $franchiseId, int $quantity, string $idempotencyKey, ?string $serviceItemId = null): BenefitUsage
    {
        $this->authorize($actor, $franchiseId);
        if (! $idempotencyKey || strlen($idempotencyKey) > 200 || $quantity < 1) {
            throw new DomainException('RESERVATION_INVALID', 'Informe quantidade e chave de operação válidas.', 422);
        }

        return DB::transaction(function () use ($subscription, $rule, $actor, $franchiseId, $quantity, $idempotencyKey, $serviceItemId) {
            $s = $this->lockSubscription($subscription);
            if ($existing = BenefitUsage::where('idempotency_key', $idempotencyKey)->first()) {
                if ($existing->subscription_id !== $s->id || $existing->benefit_rule_id !== $rule->id || $existing->quantity !== $quantity || $existing->servicing_franchise_id !== $franchiseId || $existing->service_item_id !== $serviceItemId) {
                    throw new DomainException('IDEMPOTENCY_CONFLICT', 'Esta chave já foi utilizada com outros dados.');
                }

                return $existing;
            }
            if ($serviceItemId && BenefitUsage::where('service_item_id', $serviceItemId)->exists()) {
                throw new DomainException('SERVICE_ALREADY_AUTHORIZED', 'Este item de atendimento já possui uma autorização.');
            }
            $decision = $this->eligibility->benefit($s, $rule, $franchiseId, $quantity);
            $this->requireEligible($decision);
            $expires = now()->addMinutes((int) config('plans.reservation_minutes', 60));
            if ($decision['period_end'] && $expires->gt($end = Carbon::parse($decision['period_end']))) {
                $expires = $end;
            }
            $usage = BenefitUsage::create(['subscription_id' => $s->id, 'benefit_rule_id' => $rule->id, 'customer_id' => $s->customer_id,
                'pet_id' => $s->pet_id, 'servicing_franchise_id' => $franchiseId, 'actor_id' => $actor->id, 'quantity' => $quantity,
                'status' => 'reserved', 'period_start' => $decision['period_start'], 'period_end' => $decision['period_end'], 'expires_at' => $expires,
                'idempotency_key' => $idempotencyKey, 'service_item_id' => $serviceItemId, 'rule_snapshot' => $rule->toArray()]);
            Audit::record('benefit_reserved', $usage, $actor, ['decision' => $decision], null, $franchiseId);

            return $usage;
        }, 3);
    }

    public function consume(BenefitUsage $usage, User $actor): BenefitUsage
    {
        $this->authorize($actor, $usage->servicing_franchise_id);
        $subscription = $usage->subscription;

        return DB::transaction(function () use ($usage, $actor, $subscription) {
            $s = $this->lockSubscription($subscription);
            $u = BenefitUsage::lockForUpdate()->findOrFail($usage->id);
            if ($u->status === 'consumed') {
                return $u;
            }
            if ($u->status !== 'reserved' || ! $u->expires_at || $u->expires_at->lte(now())) {
                throw new DomainException('RESERVATION_EXPIRED', 'Reserva expirada ou sem validade para execução.');
            }
            $decision = $this->eligibility->benefit($s, $u->benefitRule, $u->servicing_franchise_id, $u->quantity, $u->id);
            $this->requireEligible($decision);
            if (! Carbon::parse($decision['period_start'])->equalTo($u->period_start)) {
                throw new DomainException('BENEFIT_PERIOD_CHANGED', 'A reserva pertence a um período encerrado. Solicite nova autorização.');
            }
            $u->update(['status' => 'consumed', 'consumed_at' => now()]);
            Audit::record('benefit_consumed', $u, $actor, ['quantity' => $u->quantity, 'decision' => $decision], null, $u->servicing_franchise_id);

            return $u->fresh();
        }, 3);
    }

    public function cancelReservation(BenefitUsage $usage, User $actor, string $reason): BenefitUsage
    {
        $this->authorize($actor, $usage->servicing_franchise_id);
        if (trim($reason) === '') {
            throw new DomainException('REASON_REQUIRED', 'Informe o motivo do cancelamento.', 422);
        }
        $subscription = $usage->subscription;

        return DB::transaction(function () use ($usage, $actor, $reason, $subscription) {
            $this->lockSubscription($subscription);
            $u = BenefitUsage::lockForUpdate()->findOrFail($usage->id);
            if ($u->status === 'cancelled') {
                return $u;
            }
            if ($u->status !== 'reserved') {
                throw new DomainException('RESERVATION_NOT_CANCELLABLE', 'Somente uma reserva não executada pode ser cancelada.');
            }
            $u->update(['status' => 'cancelled', 'reason' => $reason]);
            Audit::record('benefit_reservation_cancelled', $u, $actor, [], $reason, $u->servicing_franchise_id);

            return $u;
        }, 3);
    }

    public function reverse(BenefitUsage $usage, User $actor, int $quantity, string $reason, ?string $idempotencyKey = null): BenefitUsage
    {
        $this->authorize($actor, $usage->servicing_franchise_id);
        if (! in_array($actor->role, ['central_admin', 'manager'], true)) {
            throw new DomainException('FORBIDDEN', 'Seu perfil não pode estornar utilizações.', 403);
        }
        if ($quantity < 1 || mb_strlen(trim($reason)) < 5) {
            throw new DomainException('REVERSAL_INVALID', 'Informe quantidade e motivo do estorno.', 422);
        }

        if ($idempotencyKey !== null && (strlen($idempotencyKey) > 200 || trim($idempotencyKey) === '')) {
            throw new DomainException('IDEMPOTENCY_KEY_INVALID', 'Informe uma chave de operação válida.', 422);
        }
        $subscription = $usage->subscription;

        return DB::transaction(function () use ($usage, $actor, $quantity, $reason, $idempotencyKey, $subscription) {
            $this->lockSubscription($subscription);
            $u = BenefitUsage::lockForUpdate()->findOrFail($usage->id);
            if ($idempotencyKey && $existing = BenefitUsage::where('idempotency_key', $idempotencyKey)->first()) {
                if ($existing->status !== 'reversal' || $existing->original_usage_id !== $u->id || $existing->quantity !== $quantity || $existing->reason !== $reason) {
                    throw new DomainException('IDEMPOTENCY_CONFLICT', 'Esta chave já foi utilizada com outros dados.');
                }

                return $existing;
            }
            if ($u->status !== 'consumed') {
                throw new DomainException('USAGE_NOT_CONSUMED', 'Só é possível estornar um atendimento executado.');
            }
            $reversed = (int) $u->reversals()->sum('quantity');
            if ($quantity > $u->quantity - $reversed) {
                throw new DomainException('REVERSAL_EXCEEDS_USAGE', 'O estorno excede a quantidade consumida.');
            }
            $reverse = BenefitUsage::create(['subscription_id' => $u->subscription_id, 'benefit_rule_id' => $u->benefit_rule_id,
                'customer_id' => $u->customer_id, 'pet_id' => $u->pet_id, 'servicing_franchise_id' => $u->servicing_franchise_id,
                'actor_id' => $actor->id, 'original_usage_id' => $u->id, 'quantity' => $quantity, 'status' => 'reversal',
                'period_start' => $u->period_start, 'period_end' => $u->period_end, 'consumed_at' => now(),
                'idempotency_key' => $idempotencyKey ?? 'reversal:'.Str::uuid(), 'reason' => $reason, 'rule_snapshot' => $u->rule_snapshot]);
            Audit::record('benefit_reversed', $reverse, $actor, ['original_usage_id' => $u->id, 'quantity' => $quantity], $reason, $u->servicing_franchise_id);

            return $reverse;
        }, 3);
    }

    // Callers load the subscription before the transaction: in MySQL (REPEATABLE READ) the first plain read
    // fixes the transaction snapshot, so a read before these locks would hide usages committed while waiting.
    private function lockSubscription(Subscription $subscription): Subscription
    {
        Customer::lockForUpdate()->findOrFail($subscription->customer_id);
        Pet::lockForUpdate()->findOrFail($subscription->pet_id);

        return Subscription::lockForUpdate()->findOrFail($subscription->id);
    }

    private function authorize(User $actor, int $franchiseId): void
    {
        if (! $actor->active || ! in_array($actor->role, ['central_admin', 'manager', 'operator'], true) || ! $actor->hasFranchise($franchiseId)) {
            throw new DomainException('FORBIDDEN', 'Seu perfil não pode executar benefícios nesta unidade.', 403);
        }
    }

    private function requireEligible(array $decision): void
    {
        if (! $decision['eligible']) {
            throw new DomainException($decision['code'], $decision['message'], 409, $decision);
        }
    }
}

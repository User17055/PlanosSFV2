<?php

namespace App\Services\Domain;

use App\Models\BenefitRule;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

class PeriodCalculator
{
    /** Inclusive start, exclusive end, all returned instants in UTC. Anniversary never drifts after February. */
    public function calculate(Subscription $subscription, BenefitRule $rule, ?CarbonImmutable $at = null): array
    {
        $at = ($at ?? CarbonImmutable::now('UTC'))->setTimezone('America/Sao_Paulo');
        $anchor = CarbonImmutable::parse($subscription->effective_start_at)->setTimezone('America/Sao_Paulo');
        if ($rule->period_type === 'calendar_month') {
            $start = $at->startOfMonth();
            $end = $start->addMonth();
        } elseif ($rule->period_type === 'monthly_cycle') {
            $n = max(0, ($at->year - $anchor->year) * 12 + $at->month - $anchor->month);
            while ($n > 0 && $anchor->addMonthsNoOverflow($n)->gt($at)) {
                $n--;
            }
            $start = $anchor->addMonthsNoOverflow($n);
            $end = $anchor->addMonthsNoOverflow($n + 1);
        } elseif ($rule->period_type === 'contract_year') {
            $n = max(0, $at->year - $anchor->year);
            while ($n > 0 && $anchor->addYearsNoOverflow($n)->gt($at)) {
                $n--;
            }
            $start = $anchor->addYearsNoOverflow($n);
            $end = $anchor->addYearsNoOverflow($n + 1);
        } elseif ($rule->period_type === 'lifetime') {
            $start = $anchor;
            $end = $subscription->ends_at ? CarbonImmutable::parse($subscription->ends_at) : null;
        } else {
            throw new DomainException('PERIOD_RULE_UNSUPPORTED', 'Esta regra de período ainda não está habilitada.', 422);
        }
        if ($end && $subscription->ends_at && $end->gt($subscription->ends_at)) {
            $end = CarbonImmutable::parse($subscription->ends_at);
        }

        return [$start->utc(), $end?->utc()];
    }
}

<?php

namespace App\Services\Domain;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Monthly bills are generated on a fixed day and due a few days later. The entry paid at signing is the first
 * installment; contracts closed up to the cutoff day get the next bill in the following month, later ones skip a month.
 */
class BillingSchedule
{
    /** $freeMonths: mensalidades grátis da campanha, que empurram a primeira cobrança mensal. */
    public function firstRecurringBilling(CarbonInterface $closedAt, ?CarbonInterface $today = null, int $freeMonths = 0): CarbonImmutable
    {
        $today = CarbonImmutable::instance($today ?? now())->setTimezone('America/Sao_Paulo')->startOfDay();
        $first = $this->billingAfter(CarbonImmutable::instance($closedAt)->setTimezone('America/Sao_Paulo'));
        // A payment made after that date was already reached starts the schedule from the payment day instead.
        return ($first->greaterThan($today) ? $first : $this->billingAfter($today))->addMonthsNoOverflow(max(0, $freeMonths));
    }

    public function dueDate(CarbonInterface $billing): CarbonImmutable
    {
        return CarbonImmutable::instance($billing)->setTimezone('America/Sao_Paulo')->startOfMonth()->addDays($this->day('due_day') - 1);
    }

    private function billingAfter(CarbonImmutable $date): CarbonImmutable
    {
        $months = $date->day <= $this->day('cutoff_day') ? 1 : 2;
        return $date->startOfMonth()->addMonthsNoOverflow($months)->addDays($this->day('generation_day') - 1)->startOfDay();
    }

    private function day(string $key): int
    {
        return max(1, min(28, (int) config('plans.billing.'.$key)));
    }
}

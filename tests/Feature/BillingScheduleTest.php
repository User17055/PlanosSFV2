<?php

namespace Tests\Feature;

use App\Services\Domain\BillingSchedule;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class BillingScheduleTest extends TestCase
{
    private function first(string $closedAt, ?string $today = null): string
    {
        $zone = 'America/Sao_Paulo';
        return app(BillingSchedule::class)->firstRecurringBilling(CarbonImmutable::parse($closedAt, $zone),
            CarbonImmutable::parse($today ?? $closedAt, $zone))->format('Y-m-d');
    }

    public function test_contracts_closed_up_to_day_25_are_billed_next_month_and_later_ones_skip_a_month(): void
    {
        $this->assertSame('2026-11-01', $this->first('2026-10-01 09:00'));
        $this->assertSame('2026-11-01', $this->first('2026-10-20 15:00'));
        $this->assertSame('2026-11-01', $this->first('2026-10-25 23:59'));
        $this->assertSame('2026-12-01', $this->first('2026-10-26 00:00'));
        $this->assertSame('2026-12-01', $this->first('2026-10-27 10:00'));
        $this->assertSame('2027-02-01', $this->first('2026-12-27 10:00'));
        $this->assertSame('2027-03-01', $this->first('2027-01-31 10:00'));
    }

    public function test_entry_paid_after_the_planned_billing_day_moves_the_schedule_forward(): void
    {
        $this->assertSame('2026-12-01', $this->first('2026-10-20 15:00', '2026-11-03 10:00'));
        $this->assertSame('2027-01-01', $this->first('2026-10-20 15:00', '2026-11-27 10:00'));
    }

    public function test_bills_are_due_on_day_10_and_days_are_configurable(): void
    {
        $schedule = app(BillingSchedule::class);
        $this->assertSame('2026-12-10', $schedule->dueDate(CarbonImmutable::parse('2026-12-01', 'America/Sao_Paulo'))->format('Y-m-d'));
        config(['plans.billing.cutoff_day' => 20, 'plans.billing.generation_day' => 5, 'plans.billing.due_day' => 15]);
        $this->assertSame('2026-12-05', $this->first('2026-10-21 10:00'));
        $this->assertSame('2026-12-15', $schedule->dueDate(CarbonImmutable::parse('2026-12-05', 'America/Sao_Paulo'))->format('Y-m-d'));
    }
}

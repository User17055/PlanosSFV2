<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class FinancePresentationTest extends TestCase
{
    public function test_paid_badge_does_not_inherit_the_last_metric_label(): void
    {
        $html = Blade::render('@php $label = "Saldo vencido"; @endphp @include("components.status", ["status" => "paid"])');

        $this->assertStringContainsString('badge-green', $html);
        $this->assertStringContainsString('Paga</span>', $html);
        $this->assertStringNotContainsString('Saldo vencido', $html);
    }

    public function test_verified_reconciliation_is_displayed_in_portuguese(): void
    {
        $html = view('components.status', ['status' => 'verified', 'label' => 'Saldo vencido'])->render();

        $this->assertStringContainsString('Verificada</span>', $html);
        $this->assertStringNotContainsString('Verified', $html);
        $this->assertStringNotContainsString('Saldo vencido', $html);
    }

    public function test_only_the_explicit_status_label_can_override_the_translation(): void
    {
        $html = view('components.status', ['status' => 'pending', 'label' => 'Saldo vencido', 'statusLabel' => 'Verificação pendente'])->render();

        $this->assertStringContainsString('Verificação pendente</span>', $html);
        $this->assertStringNotContainsString('Saldo vencido', $html);
    }
}

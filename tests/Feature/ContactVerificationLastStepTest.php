<?php

namespace Tests\Feature;

use App\Notifications\PortalVerifyEmail;
use App\Services\Domain\ActivationService;
use App\Services\Domain\ContactVerificationService;
use App\Services\Domain\EligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsActiveSubscription;
use Tests\TestCase;

/** Decisão de 09/11/2026: o plano ativa com o pagamento; o e-mail confirmado fica como aviso, para o tutor não perder o acesso. */
class ContactVerificationLastStepTest extends TestCase
{
    use BuildsActiveSubscription, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    public function test_plan_activates_after_payment_without_waiting_for_the_email(): void
    {
        $this->customer->update(['contact_verified_at' => null]);
        $this->client->forceFill(['email_verified_at' => null])->save();
        $subscription = $this->activeSubscription();

        $codes = array_column(app(EligibilityService::class)->activationImpediments($subscription->fresh()), 'code');
        $this->assertNotContains('CONTACT_NOT_VERIFIED', $codes);
        $this->assertSame('active', $subscription->fresh()->status);
        // O aviso continua na área do cliente enquanto o e-mail não for confirmado.
        $this->actingAs($this->client)->get(route('portal'))->assertOk()->assertSee('Confirme seu e-mail');
        $this->actingAs($this->client)->get(route('portal.invoices'))->assertOk()->assertSee('Confirme seu e-mail');
    }

    public function test_confirmation_email_is_requested_after_payment_only_once_per_hour(): void
    {
        Notification::fake();
        Cache::flush();
        $this->client->forceFill(['email_verified_at' => null])->save();
        $service = app(ContactVerificationService::class);
        $service->requestAfterPayment($this->customer->id);
        $service->requestAfterPayment($this->customer->id);
        Notification::assertSentToTimes($this->client, PortalVerifyEmail::class, 1);

        Notification::fake();
        $this->client->forceFill(['email_verified_at' => now()])->save();
        $service->requestAfterPayment($this->customer->id);
        Notification::assertNothingSent();
    }
}

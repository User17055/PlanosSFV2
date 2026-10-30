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

/** A confirmação do e-mail vem por último: assina e paga antes, e ela libera a ativação. */
class ContactVerificationLastStepTest extends TestCase
{
    use BuildsActiveSubscription, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    public function test_activation_waits_for_the_email_and_resumes_when_it_is_confirmed(): void
    {
        $subscription = $this->activeSubscription();
        $this->customer->update(['contact_verified_at' => null]);
        $this->client->forceFill(['email_verified_at' => null])->save();

        $codes = array_column(app(EligibilityService::class)->activationImpediments($subscription->fresh()), 'code');
        $this->assertContains('CONTACT_NOT_VERIFIED', $codes);
        $this->assertFalse(app(ActivationService::class)->activate($subscription->fresh())['eligible']);

        $this->customer->update(['contact_verified_at' => now()]);
        $this->client->forceFill(['email_verified_at' => now()])->save();
        app(ContactVerificationService::class)->activatePending($this->customer->id);
        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_customers_without_portal_access_are_not_held_back_by_the_email(): void
    {
        $subscription = $this->activeSubscription();
        $this->client->forceFill(['customer_id' => null])->save();
        $this->customer->update(['contact_verified_at' => null]);
        $codes = array_column(app(EligibilityService::class)->activationImpediments($subscription->fresh()), 'code');
        $this->assertNotContains('CONTACT_NOT_VERIFIED', $codes);
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

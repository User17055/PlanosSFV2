<?php

namespace Tests\Feature;

use App\Models\{Customer, Franchise, Pet, Product, Proposal, Subscription, User};
use App\Notifications\PaymentReminder;
use App\Services\Domain\PaymentReminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PaymentReminderTest extends TestCase
{
    use RefreshDatabase;

    private Proposal $proposal;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $unit = Franchise::create(['name' => 'Unidade', 'code' => 'CENTRAL', 'active' => true]);
        $customer = Customer::create(['name' => 'Tutora Fictícia', 'document' => 'PAY-REM', 'email' => 'tutora@example.test', 'contact_verified_at' => now()]);
        $user = User::factory()->create(['role' => 'customer', 'customer_id' => $customer->id, 'active' => true]);
        $pet = Pet::create(['customer_id' => $customer->id, 'name' => 'Mel', 'species' => 'cat']);
        $product = Product::create(['name' => 'Plano', 'code' => 'PR', 'category' => 'health_plan', 'active' => true]);
        $version = $product->versions()->create(['version' => 1, 'name' => 'Plano Felino', 'price_cents' => 2400, 'enrollment_fee_cents' => 0, 'billing_cycle' => 'monthly',
            'duration_months' => 12, 'renewal_policy' => 'automatic', 'species' => ['cat'], 'contract_template' => 'Teste', 'status' => 'published', 'is_demo' => false, 'published_at' => now()]);
        $this->proposal = Proposal::create(['number' => 'PR-PAY', 'customer_id' => $customer->id, 'pet_id' => $pet->id, 'product_version_id' => $version->id, 'originating_franchise_id' => $unit->id,
            'created_by' => $user->id, 'category' => 'health_plan', 'channel' => 'site', 'signature_method' => 'digital', 'status' => 'accepted', 'price_cents' => 2400, 'snapshot' => [],
            'expires_at' => now()->subDay(), 'accepted_at' => now()->subDays(2), 'locked_at' => now()->subDays(2)]);
        $this->subscription = Subscription::create(['number' => 'SF-PAY', 'proposal_id' => $this->proposal->id, 'customer_id' => $customer->id, 'pet_id' => $pet->id, 'product_version_id' => $version->id,
            'originating_franchise_id' => $unit->id, 'category' => 'health_plan', 'price_cents' => 2400, 'contract_snapshot' => [], 'document_status' => 'valid', 'locked_at' => now()]);
        $this->subscription->invoices()->create(['customer_id' => $customer->id, 'originating_franchise_id' => $unit->id, 'amount_cents' => 2400, 'due_at' => now()->addDay(), 'is_initial' => true]);
    }

    public function test_signed_and_unpaid_gets_up_to_three_reminders_two_days_apart_with_a_working_link(): void
    {
        $reminders = app(PaymentReminders::class);
        $this->assertCount(1, $reminders->due());
        $this->assertTrue($reminders->send($this->proposal));
        $this->assertCount(0, $reminders->due(), 'Dois dias de intervalo entre lembretes.');
        // O link do e-mail abre o contrato pedindo o CPF, sem senha.
        $url = null;
        Notification::assertSentOnDemand(PaymentReminder::class, function (PaymentReminder $notification) use (&$url) {
            $url = (new \ReflectionProperty($notification, 'url'))->getValue($notification);
            return true;
        });
        $this->get($url)->assertOk()->assertSee('pagar a entrada');
        $this->travel(2)->days();
        $this->assertCount(1, $reminders->due());
        $reminders->send($this->proposal->fresh());
        $this->travel(2)->days();
        $reminders->send($this->proposal->fresh());
        $this->travel(2)->days();
        $this->assertCount(0, $reminders->due(), 'No máximo 3 lembretes.');
    }

    public function test_paid_entry_or_signed_less_than_a_day_ago_gets_no_reminder(): void
    {
        $this->subscription->invoices()->update(['status' => 'paid', 'paid_at' => now()]);
        $this->assertCount(0, app(PaymentReminders::class)->due());
        $this->subscription->invoices()->update(['status' => 'pending', 'paid_at' => null]);
        $this->travelTo($this->proposal->accepted_at->copy()->addHours(3)); // assinou há 3 horas
        $this->assertCount(0, app(PaymentReminders::class)->due());
    }
}

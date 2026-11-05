<?php

namespace Tests\Feature;

use App\Models\{Customer,Franchise,Pet,Product,ProductVersion,Proposal,User};
use App\Services\Domain\ProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth,Notification,Storage};
use Tests\TestCase;

/** Quem recebe o link assina sem senha, depois de confirmar o CPF. O resto do painel continua pedindo a senha. */
class SigningLinkTest extends TestCase
{
    use RefreshDatabase;

    private const CPF = '52998224725';

    private Customer $customer;
    private User $admin;
    private Franchise $unit;
    private ProductVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        config(['plans.demo' => true]);
        Storage::fake('local');
        Notification::fake();
        $this->unit = Franchise::create(['name' => 'Unidade teste', 'code' => 'CENTRAL', 'active' => true]);
        $this->admin = User::factory()->create(['role' => 'central_admin', 'active' => true]);
        $this->customer = Customer::create(['name' => 'Maria Titular', 'document' => self::CPF, 'email' => 'titular@example.test', 'active' => true]);
        $product = Product::create(['name' => 'Plano de teste', 'code' => 'TEST', 'category' => 'health_plan', 'active' => true]);
        $this->version = ProductVersion::create(['product_id' => $product->id, 'version' => 1, 'name' => 'Versão teste', 'status' => 'published', 'published_at' => now(),
            'price_cents' => 8600, 'billing_cycle' => 'monthly', 'duration_months' => 12, 'species' => ['dog'], 'contract_template' => 'Titular: {{contratante_nome}}']);
    }

    private function proposal(string $pet = 'Lua'): Proposal
    {
        $pet = Pet::create(['customer_id' => $this->customer->id, 'name' => $pet, 'species' => 'dog', 'birth_date' => '2020-01-01', 'active' => true]);
        return app(ProposalService::class)->create(['customer_id' => $this->customer->id, 'pet_id' => $pet->id, 'product_version_id' => $this->version->id,
            'originating_franchise_id' => $this->unit->id, 'channel' => 'site', 'signature_method' => 'digital'], $this->admin);
    }

    private function link(?Proposal $proposal = null): array
    {
        $proposal ??= $this->proposal();
        return [$proposal, app(ProposalService::class)->issueLink($proposal)];
    }

    public function test_link_asks_only_the_cpf_and_shows_no_personal_data_before_it(): void
    {
        [, $token] = $this->link();
        $this->get(route('sales.proposal', $token))->assertOk()->assertSee('CPF do titular')->assertSee('não precisa de senha')
            ->assertDontSee('Maria Titular')->assertDontSee('Lua')->assertDontSee(self::CPF)->assertDontSee('type="password"', false);
        $this->assertGuest();
    }

    public function test_right_cpf_opens_the_contract_without_a_password_and_creates_the_access_once(): void
    {
        [$proposal, $token] = $this->link();
        $this->post(route('sales.proposal.confirm', $token), ['document' => '529.982.247-25'])->assertRedirect(route('proposals.show', $proposal));
        $this->assertAuthenticated();
        $user = User::where('customer_id', $this->customer->id)->sole();
        $this->assertSame(['customer', 'titular@example.test'], [$user->role, $user->email]);
        $this->assertNotNull($this->customer->fresh()->contact_verified_at);
        $this->get(route('proposals.show', $proposal))->assertOk()->assertSee('data-signature-form', false);

        Auth::logout();
        $this->flushSession();
        $this->post(route('sales.proposal.confirm', $token), ['document' => self::CPF])->assertRedirect(route('proposals.show', $proposal));
        $this->assertSame(1, User::where('customer_id', $this->customer->id)->count());
    }

    public function test_wrong_cpf_is_refused_and_too_many_attempts_lock_the_link(): void
    {
        [$proposal, $token] = $this->link();
        foreach (['11144477735', 'abc', '11144477735', '11144477735', '11144477735'] as $wrong) {
            $this->post(route('sales.proposal.confirm', $token), ['document' => $wrong])->assertSessionHasErrors('document');
        }
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['customer_id' => $this->customer->id]);
        // Mesmo o CPF certo espera depois de 5 erros seguidos.
        $this->post(route('sales.proposal.confirm', $token), ['document' => self::CPF])->assertSessionHasErrors('document');
        $this->assertGuest();
        $this->travel(16)->minutes();
        $this->post(route('sales.proposal.confirm', $token), ['document' => self::CPF])->assertRedirect(route('proposals.show', $proposal));
    }

    public function test_session_from_the_link_reaches_only_that_contract_and_the_panel_asks_for_the_password(): void
    {
        [$proposal, $token] = $this->link();
        $other = $this->proposal('Bob');
        $this->post(route('sales.proposal.confirm', $token), ['document' => self::CPF]);
        $this->get(route('proposals.show', $proposal))->assertOk();

        $this->get(route('proposals.show', $other))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post(route('sales.proposal.confirm', $token), ['document' => self::CPF]);
        $this->get(route('portal'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get(route('login'))->assertSee('entre com sua senha')->assertSee(route('password.request'), false);

        $this->post(route('sales.proposal.confirm', $token), ['document' => self::CPF]);
        $this->post(route('proposals.cancel', $proposal))->assertForbidden();
        $this->assertGuest();
    }

    public function test_person_signs_and_reaches_payment_through_the_link(): void
    {
        [$proposal, $token] = $this->link();
        $this->post(route('sales.proposal.confirm', $token), ['document' => self::CPF]);
        $fingerprint = app(ProposalService::class)->reviewFingerprint($proposal->fresh());
        $this->post(route('proposals.accept', $proposal), ['accepted' => 1, 'review_fingerprint' => $fingerprint, 'signature_data' => $this->signatureData()])->assertRedirect(route('proposals.show', $proposal));

        $signed = $proposal->fresh();
        $this->assertNotNull($signed->accepted_at);
        $this->assertSame('signature_link', $signed->acceptance_evidence['source']);
        $this->assertNotNull($signed->link_revoked_at);
        $subscription = $signed->subscription;
        // Com a Vindi desligada no teste, o pagamento volta à tela do contrato, sem perder a sessão.
        $this->get(route('proposals.show', $proposal))->assertOk();
        $this->post(route('subscriptions.checkout', $subscription))->assertRedirect();
        $this->assertAuthenticated();

        // Reabrir o link depois de assinado, sem sessão, leva à senha.
        Auth::logout();
        $this->flushSession();
        $this->get(route('sales.proposal', $token))->assertRedirect(route('login'));
        $this->get(route('login'))->assertSee('já foi assinado');
        $this->post(route('sales.proposal.confirm', $token), ['document' => self::CPF])->assertNotFound();
    }

    public function test_customer_who_already_has_a_password_also_signs_without_it_and_normal_login_keeps_the_panel(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'active' => true, 'customer_id' => $this->customer->id, 'email' => 'titular@example.test', 'email_verified_at' => now()]);
        [$proposal, $token] = $this->link();
        $this->post(route('sales.proposal.confirm', $token), ['document' => self::CPF])->assertRedirect(route('proposals.show', $proposal));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::where('customer_id', $this->customer->id)->count());

        Auth::logout();
        $this->flushSession();
        $this->actingAs($user)->get(route('portal'))->assertOk();
    }

    public function test_link_stays_closed_when_it_cannot_identify_the_person_or_was_cancelled(): void
    {
        [$proposal, $token] = $this->link();
        // E-mail já usado por outro acesso: não dá para criar o acesso do titular.
        User::factory()->create(['role' => 'sales', 'active' => true, 'email' => 'titular@example.test']);
        $this->post(route('sales.proposal.confirm', $token), ['document' => self::CPF])->assertSessionHasErrors('document');
        $this->assertGuest();

        $this->get(route('sales.proposal', str_repeat('x', 64)))->assertNotFound();
        $this->get(route('sales.proposal', 'curto'))->assertNotFound();
        $proposal->update(['link_expires_at' => now()->subMinute()]);
        $this->get(route('sales.proposal', $token))->assertNotFound();
        $proposal->update(['link_expires_at' => now()->addDay(), 'link_revoked_at' => now()]);
        $this->get(route('sales.proposal', $token))->assertNotFound();
        $proposal->update(['link_revoked_at' => null, 'signature_method' => 'paper']);
        $this->get(route('sales.proposal', $token))->assertNotFound();
        $this->post(route('sales.proposal.confirm', $token), ['document' => self::CPF])->assertNotFound();
    }
}

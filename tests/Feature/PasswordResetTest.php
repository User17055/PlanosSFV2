<?php

namespace Tests\Feature;

use App\Models\{Customer, Franchise, User};
use App\Notifications\PortalPasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Hash, Notification};
use Tests\TestCase;

/** Criar ou recuperar senha na gestão: equipe, cliente com senha e cliente que ainda não tem acesso. */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function linkFor(User $user): string
    {
        $url = null;
        Notification::assertSentTo($user, PortalPasswordReset::class, function ($notification) use ($user, &$url) {
            $url = $notification->toMail($user)->actionUrl;
            return true;
        });
        return $url;
    }

    public function test_login_page_links_to_the_management_recovery_with_the_same_look(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(route('password.request'), false)->assertDontSee('/area-do-cliente/esqueci-senha');
        $this->get(route('password.request'))->assertOk()->assertSee('Criar ou recuperar senha')->assertSee('auth-card', false);
    }

    public function test_staff_member_recovers_the_password_and_logs_in(): void
    {
        Notification::fake();
        $unit = Franchise::create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $staff = User::create(['name' => 'Ana', 'email' => 'Ana@Example.test', 'password' => 'SenhaAntiga@2026', 'role' => 'manager', 'active' => true, 'franchise_id' => $unit->id]);
        $this->post(route('password.email'), ['email' => 'ana@example.test'])->assertRedirect()->assertSessionHas('status');
        $url = $this->linkFor($staff);
        $this->assertStringStartsWith(url('/redefinir-senha/'), $url);

        $token = basename(parse_url($url, PHP_URL_PATH));
        $this->get($url)->assertOk()->assertSee('Criar nova senha');
        $this->post(route('password.update'), ['token' => $token, 'email' => $staff->email, 'password' => 'curta', 'password_confirmation' => 'curta'])->assertSessionHasErrors('password');
        $this->post(route('password.update'), ['token' => $token, 'email' => $staff->email, 'password' => 'SenhaNova@2026x', 'password_confirmation' => 'SenhaNova@2026x'])
            ->assertRedirect(route('login', ['login' => $staff->email]));
        $this->assertTrue(Hash::check('SenhaNova@2026x', $staff->fresh()->password));
        // O mesmo link não serve duas vezes.
        $this->post(route('password.update'), ['token' => $token, 'email' => $staff->email, 'password' => 'OutraSenha@2026', 'password_confirmation' => 'OutraSenha@2026'])->assertSessionHasErrors('email');
    }

    public function test_customer_without_access_gets_one_and_unknown_or_inactive_emails_send_nothing(): void
    {
        Notification::fake();
        $unit = Franchise::create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $customer = Customer::create(['name' => 'Tutor Importado', 'document' => '52998224725', 'email' => 'importado@example.test', 'phone' => '18999990000', 'originating_franchise_id' => $unit->id]);
        User::create(['name' => 'Inativo', 'email' => 'inativo@example.test', 'password' => 'SenhaAntiga@2026', 'role' => 'sales', 'active' => false]);

        $answer = fn (string $email) => $this->post(route('password.email'), ['email' => $email])->assertRedirect()->assertSessionHas('status')->getSession()->get('status');
        $this->assertSame($answer('ninguem@example.test'), $answer('importado@example.test'));
        $answer('inativo@example.test');

        $user = User::where('customer_id', $customer->id)->sole();
        $this->assertSame(['customer', true], [$user->role, $user->active]);
        Notification::assertSentTo($user, PortalPasswordReset::class);
        Notification::assertSentTimes(PortalPasswordReset::class, 1);
    }
}

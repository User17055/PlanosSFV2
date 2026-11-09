<?php

namespace Tests\Feature;

use App\Models\{Customer, Franchise, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSwitchTest extends TestCase
{
    use RefreshDatabase;

    private function people(): array
    {
        $unit = Franchise::create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $admin = User::factory()->create(['role' => 'central_admin', 'active' => true, 'email' => 'admin@example.test', 'password' => 'Senha-Admin-2026']);
        $customer = Customer::create(['name' => 'Andre Tutor', 'document' => '52998224725', 'email' => 'tutor@example.test', 'phone' => '18999990000', 'originating_franchise_id' => $unit->id]);
        $tutor = User::factory()->create(['role' => 'customer', 'active' => true, 'customer_id' => $customer->id, 'email' => 'tutor@example.test', 'password' => 'Senha-Tutor-2026']);
        return [$admin, $tutor];
    }

    public function test_only_central_admin_is_asked_where_to_enter(): void
    {
        [$admin, $tutor] = $this->people();
        $this->post(route('authenticate'), ['email' => 'admin@example.test', 'password' => 'Senha-Admin-2026'])->assertRedirect(route('account.choose'));
        $this->get(route('account.choose'))->assertOk()->assertSee('Painel da administração')->assertSee('Senha da conta de cliente');
        $this->post(route('account.admin'))->assertRedirect(route('dashboard'));
        $this->post(route('logout'));
        $this->post(route('authenticate'), ['email' => 'tutor@example.test', 'password' => 'Senha-Tutor-2026'])->assertRedirect(route('portal'));
        $this->get(route('account.choose'))->assertForbidden();
        $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
        $this->actingAs($manager)->get(route('account.choose'))->assertForbidden();
    }

    public function test_customer_area_needs_the_customer_password_once_and_then_switches_both_ways(): void
    {
        [$admin, $tutor] = $this->people();
        $this->actingAs($admin)->post(route('account.customer'), ['login' => 'tutor@example.test', 'password' => 'errada'])->assertSessionHasErrors('password');
        $this->assertNull($admin->fresh()->linked_customer_user_id);
        $this->assertAuthenticatedAs($admin);

        $this->post(route('account.customer'), ['login' => '529.982.247-25', 'password' => 'Senha-Tutor-2026'])->assertRedirect(route('portal'));
        $this->assertSame($tutor->id, $admin->fresh()->linked_customer_user_id);
        $this->assertAuthenticatedAs($tutor);
        $this->get(route('portal'))->assertOk()->assertSee('Voltar ao painel da administração');

        $this->post(route('account.back'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);
        // Já vinculada: a troca não pede a senha de novo.
        $this->post(route('account.customer'))->assertRedirect(route('portal'));
        $this->assertAuthenticatedAs($tutor);
    }

    public function test_a_customer_cannot_jump_into_the_admin_panel_without_the_admin_session(): void
    {
        [$admin, $tutor] = $this->people();
        $admin->forceFill(['linked_customer_user_id' => $tutor->id])->save();
        $this->actingAs($tutor)->post(route('account.back'))->assertForbidden();
        $this->assertAuthenticatedAs($tutor);
        $other = User::factory()->create(['role' => 'central_admin', 'active' => true]);
        $this->actingAs($other)->post(route('account.customer'), ['login' => 'tutor@example.test', 'password' => 'Senha-Tutor-2026'])->assertSessionHasErrors('login');
        $this->assertAuthenticatedAs($other);
    }
}

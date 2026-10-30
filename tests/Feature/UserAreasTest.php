<?php

namespace Tests\Feature;

use App\Models\{Franchise,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** O administrador escolhe, no cadastro do funcionário, quais telas ele pode abrir (sempre dentro do que o perfil permite). */
class UserAreasTest extends TestCase
{
    use RefreshDatabase;

    private Franchise $unit;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = Franchise::create(['name' => 'Canal central', 'code' => 'CENTRAL', 'active' => true]);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'Example123!!!', 'role' => 'central_admin', 'active' => true]);
    }

    private function saveSeller(array $areas, ?User $seller = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post($seller ? route('employees.update', $seller) : route('employees.store'), ['name' => 'Bia Vendas', 'email' => 'bia@example.test',
            'password' => $seller ? '' : 'Example123!!!', 'password_confirmation' => $seller ? '' : 'Example123!!!', 'active' => 1, 'role' => 'sales',
            'franchise_ids' => [$this->unit->id], 'areas_shown' => 1, 'areas' => $areas]);
    }

    public function test_unchecked_screens_leave_the_menu_and_are_closed(): void
    {
        // "finance" não é do perfil de vendas: é descartado mesmo marcado.
        $this->saveSeller(['leads', 'proposals', 'finance'])->assertRedirect(route('employees.index'));
        $seller = User::where('email', 'bia@example.test')->first();
        $this->assertSame(['leads', 'proposals'], $seller->areas);

        $this->actingAs($seller);
        $this->get(route('dashboard'))->assertRedirect(route('leads.index'));
        $this->get(route('customers.index'))->assertRedirect(route('leads.index'));
        $this->post(route('customers.store'), [])->assertForbidden();
        $this->get(route('proposals.index'))->assertOk();
        $this->get(route('leads.index'))->assertOk()->assertSee('Propostas')->assertDontSee('Tutores e pets')->assertDontSee('Buscar tutor');
    }

    public function test_everything_checked_means_the_whole_profile_and_nothing_checked_is_refused(): void
    {
        $this->saveSeller(User::areasFor('sales'))->assertRedirect();
        $seller = User::where('email', 'bia@example.test')->first();
        $this->assertNull($seller->areas);
        $this->saveSeller([], $seller)->assertSessionHasErrors('areas');
        $this->assertNull($seller->fresh()->areas);
    }

    public function test_admin_cannot_restrict_their_own_screens(): void
    {
        $this->actingAs($this->admin)->get(route('employees.edit', $this->admin))->assertOk()->assertSee('Outro administrador deve alterar o que você pode abrir.');
    }
}

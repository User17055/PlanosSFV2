<?php

namespace Tests\Feature;

use App\Models\{Customer,Franchise,JobRole,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Cargo: o administrador escolhe, por tópico, o que a pessoa pode ver e fazer, sempre dentro do nível de acesso. */
class JobRoleTest extends TestCase
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

    private function saveRole(array $permissions, string $level = 'manager', string $name = 'Recepção', ?JobRole $role = null)
    {
        return $this->actingAs($this->admin)->post($role ? route('employees.roles.update', $role) : route('employees.roles.store'), ['name' => $name, 'base_role' => $level, 'permissions' => $permissions]);
    }

    private function saveEmployee(array $fields, ?User $employee = null)
    {
        return $this->actingAs($this->admin)->post($employee ? route('employees.update', $employee) : route('employees.store'), $fields + ['name' => 'Rita Recepção', 'email' => 'rita@example.test',
            'password' => $employee ? '' : 'Example123!!!', 'password_confirmation' => $employee ? '' : 'Example123!!!', 'active' => 1, 'role' => 'sales', 'franchise_ids' => [$this->unit->id]]);
    }

    /** Recepção: assina e cuida dos tutores, mas não vê o financeiro. */
    private function receptionist(array $permissions = ['leads.view', 'customers.view', 'customers.update', 'proposals.view', 'proposals.create', 'proposals.sign']): User
    {
        $this->saveRole($permissions)->assertRedirect(route('employees.roles.index'));
        $this->saveEmployee(['job_role_id' => JobRole::firstWhere('name', 'Recepção')->id])->assertRedirect(route('employees.index'));
        return User::firstWhere('email', 'rita@example.test');
    }

    public function test_every_option_points_to_real_routes_and_no_staff_route_is_forgotten(): void
    {
        foreach (JobRole::OPTIONS as $key => [, $routes]) {
            foreach ($routes as $name) $this->assertNotNull(Route::getRoutes()->getByName($name), "$key aponta para uma rota que não existe: $name");
        }
        // Cada tópico abre pela tela principal, para onde a pessoa é levada ao cair numa tela fechada.
        foreach (array_keys(User::AREAS) as $area) {
            if (! isset(JobRole::OPTIONS[$area.'.view'])) continue;
            $this->assertContains($area === 'dashboard' ? 'dashboard' : $area.'.index', JobRole::OPTIONS[$area.'.view'][1]);
        }
        // Rota nova que a equipe alcança precisa entrar em uma opção; sem isso, ela fica fechada para quem tem cargo.
        $levels = array_keys(JobRole::levels());
        foreach (Route::getRoutes() as $route) {
            $middleware = array_filter($route->gatherMiddleware(), 'is_string');
            if (! in_array('active', $middleware, true) || $route->getName() === 'logout') continue;
            $reachable = $levels;
            foreach ($middleware as $item) {
                if (str_starts_with($item, 'role:')) $reachable = array_intersect($reachable, explode(',', substr($item, 5)));
            }
            if ($reachable) $this->assertNotNull(JobRole::optionOf($route->getName()), 'Rota sem opção de cargo: '.$route->getName());
        }
    }

    public function test_options_offered_to_each_level_are_ones_its_routes_accept(): void
    {
        $this->assertNotContains('manager', JobRole::rolesOf('finance.confirm'));
        $this->assertContains('finance', JobRole::rolesOf('finance.confirm'));
        $this->assertSame(['sales', 'manager'], array_values(array_intersect(['sales', 'manager'], JobRole::rolesOf('proposals.sign'))));
        $this->assertSame(['leads.view'], JobRole::clean(['leads.view', 'customers.view'], 'attendant'));
        // Marcar uma opção libera ver o tópico.
        $this->assertSame(['proposals.view', 'proposals.sign'], JobRole::clean(['proposals.sign'], 'sales'));
        // Administração central nunca é nível de cargo: quem cuida dos cargos não pode se trancar para fora.
        $this->saveRole(['leads.view'], 'central_admin')->assertSessionHasErrors('base_role');
        $this->saveRole([])->assertSessionHasErrors('permissions');
        $this->saveRole(['finance.confirm'], 'sales')->assertSessionHasErrors('permissions');
    }

    public function test_receptionist_can_sign_but_does_not_see_finance(): void
    {
        $rita = $this->receptionist();
        $this->assertSame('manager', $rita->role);
        $this->actingAs($rita);

        $this->get(route('proposals.index'))->assertOk()->assertSee('Nova contratação');
        $this->get(route('leads.index'))->assertOk()->assertSee('Tutores e pets')->assertSee('Propostas')->assertDontSee('Financeiro')->assertDontSee('Comissões')->assertDontSee('Relatórios');
        // Tela fechada volta para a primeira liberada; gravação é recusada, mesmo o nível permitindo.
        $this->get(route('dashboard'))->assertRedirect(route('leads.index'));
        $this->get(route('finance.index'))->assertRedirect(route('leads.index'));
        $this->get(route('reports.export'))->assertRedirect(route('leads.index'));
        $lead = \App\Models\Lead::create(['originating_franchise_id' => $this->unit->id, 'name' => 'Maria da Silva', 'phone' => '5518999990000']);
        $this->post(route('leads.update', $lead), ['status' => 'contacted'])->assertForbidden();
        $this->post(route('leads.destroy', $lead))->assertForbidden();
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => $lead->fresh()->status]);
        // O cargo nunca vai além do nível: telas só da administração central também ficam fechadas.
        $this->get(route('management.index'))->assertRedirect(route('leads.index'));
        $this->get(route('employees.roles.index'))->assertRedirect(route('leads.index'));
    }

    public function test_tutor_file_hides_invoices_without_finance_and_shows_them_with_it(): void
    {
        $customer = Customer::create(['name' => 'Ana Tutora', 'document' => '12345678900', 'email' => 'ana@example.test', 'phone' => '18999990000', 'originating_franchise_id' => $this->unit->id, 'active' => true]);
        $rita = $this->receptionist();
        \Illuminate\Support\Facades\DB::table('user_franchises')->where('user_id', $rita->id)->update(['active' => true]);
        $this->actingAs($rita)->get(route('customers.show', $customer))->assertOk()->assertDontSee('Faturas e pagamentos');

        $this->saveRole(['customers.view', 'finance.view'], 'manager', 'Recepção', JobRole::firstWhere('name', 'Recepção'))->assertRedirect();
        $this->actingAs($rita->fresh())->get(route('customers.show', $customer))->assertOk()->assertSee('Faturas e pagamentos');
        $this->get(route('finance.index'))->assertOk();
    }

    public function test_view_only_option_hides_the_buttons(): void
    {
        $rita = $this->receptionist(['leads.view', 'proposals.view']);
        $this->actingAs($rita);
        $this->get(route('proposals.index'))->assertOk()->assertDontSee('Nova contratação');
        $this->get(route('proposals.create'))->assertRedirect(route('leads.index'));
        $this->post(route('proposals.store'), [])->assertForbidden();
    }

    public function test_employee_form_uses_the_level_of_the_role_and_people_without_role_stay_as_they_were(): void
    {
        $rita = $this->receptionist();
        $this->assertNull($rita->areas);
        // Sem cargo volta a valer o perfil escolhido no cadastro.
        $this->saveEmployee(['job_role_id' => '', 'role' => 'sales', 'email' => 'rita@example.test'], $rita)->assertRedirect();
        $rita->refresh();
        $this->assertNull($rita->job_role_id);
        $this->assertSame('sales', $rita->role);
        $this->assertTrue($rita->canUse('proposals.tablet-sign'));
        $this->assertTrue($rita->canOpen('leads'));
        // O administrador não pode dar um cargo a si mesmo.
        $role = JobRole::firstWhere('name', 'Recepção');
        $this->actingAs($this->admin)->post(route('employees.update', $this->admin), ['name' => 'Admin', 'email' => 'admin@example.test', 'password' => '', 'password_confirmation' => '', 'active' => 1, 'role' => 'central_admin', 'franchise_ids' => [$this->unit->id], 'job_role_id' => $role->id])->assertStatus(422);
        $this->assertNull($this->admin->fresh()->job_role_id);
    }

    public function test_changing_level_moves_people_and_other_screens_cannot_leave_them_out_of_sync(): void
    {
        $rita = $this->receptionist();
        $role = JobRole::firstWhere('name', 'Recepção');
        $this->saveRole(['leads.view', 'proposals.sign'], 'sales', 'Recepção', $role)->assertRedirect();
        $this->assertSame('sales', $rita->fresh()->role);
        $this->assertSame(['leads.view', 'proposals.view', 'proposals.sign'], $role->fresh()->permissions);

        // Excluir só depois de tirar o cargo das pessoas.
        $this->actingAs($this->admin)->post(route('employees.roles.destroy', $role))->assertStatus(422);
        // Trocar o perfil na tela de rede tira o cargo.
        $this->actingAs($this->admin)->post(route('management.users.update', $rita), ['active' => 1, 'role' => 'operator', 'franchise_ids' => [$this->unit->id]])->assertRedirect();
        $this->assertNull($rita->fresh()->job_role_id);
        $this->actingAs($this->admin)->post(route('employees.roles.destroy', $role))->assertRedirect(route('employees.roles.index'));
        $this->assertDatabaseMissing('job_roles', ['id' => $role->id]);
    }

    public function test_only_central_admin_manages_roles_and_the_screens_open(): void
    {
        $this->actingAs($this->admin)->get(route('employees.roles.index'))->assertOk()->assertSee('Nenhum cargo criado');
        $this->get(route('employees.roles.create'))->assertOk()->assertSee('Assinar no tablet ou em papel');
        $this->saveRole(['leads.view'])->assertRedirect();
        $this->actingAs($this->admin)->get(route('employees.roles.index'))->assertOk()->assertSee('Recepção');
        $this->get(route('employees.roles.edit', JobRole::first()))->assertOk();
        $this->get(route('employees.index'))->assertOk()->assertSee('Cargos e permissões');
        $this->get(route('employees.create'))->assertOk()->assertSee('Cargo e permissões');
        $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
        $this->actingAs($manager)->get(route('employees.roles.index'))->assertForbidden();
        $this->post(route('employees.roles.store'), [])->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Models\{Franchise,Lead,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** O perfil "Atendimento" só enxerga a tela de Leads; o resto do sistema fica fechado para ele. */
class LeadAccessTest extends TestCase
{
    use RefreshDatabase;

    private Franchise $unit;
    private User $attendant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = Franchise::create(['name' => 'Canal central', 'code' => 'CENTRAL', 'active' => true]);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'Example123!!!', 'role' => 'central_admin', 'active' => true]);
        $this->attendant = User::create(['name' => 'Ana Atendimento', 'email' => 'ana@example.test', 'password' => 'Example123!!!', 'role' => 'attendant', 'active' => true, 'franchise_id' => $this->unit->id]);
        $this->attendant->franchises()->sync([$this->unit->id => ['active' => true]]);
    }

    private function lead(array $over = []): Lead
    {
        return Lead::create($over + ['originating_franchise_id' => $this->unit->id, 'name' => 'Maria da Silva', 'phone' => '5518999990000', 'campaign' => 'Outubro']);
    }

    public function test_attendant_works_the_lead_list_with_whatsapp_message_search_and_status(): void
    {
        $maria = $this->lead();
        $this->lead(['name' => 'João Souza', 'phone' => '18988887777', 'external_id' => 'x2']);
        $page = $this->actingAs($this->attendant)->get(route('leads.index'))->assertOk();
        $page->assertSee('(18) 99999-0000')->assertSee('text=Ol%C3%A1%2C%20Maria%21', false)->assertDontSee('Rede e acessos')->assertDontSee('Tutores e pets');
        $this->actingAs($this->attendant)->get(route('leads.index', ['q' => 'souza']))->assertSee('João Souza')->assertDontSee('Maria da Silva');
        $this->actingAs($this->attendant)->get(route('leads.index', ['q' => '99999']))->assertSee('Maria da Silva')->assertDontSee('João Souza');
        $this->actingAs($this->attendant)->post(route('leads.update', $maria), ['status' => 'contacted'])->assertRedirect();
        $this->assertSame($this->attendant->id, $maria->fresh()->handled_by);
        $this->actingAs($this->attendant)->get(route('leads.index', ['situacao' => 'contacted']))->assertSee('Maria da Silva')->assertDontSee('João Souza');
    }

    public function test_attendant_is_kept_out_of_everything_else(): void
    {
        $this->actingAs($this->attendant);
        foreach (['dashboard', 'customers.index', 'proposals.index', 'finance.index', 'catalog.index', 'commissions.index', 'reports.index', 'employees.index', 'integrations.index', 'management.index', 'attribution_links.index'] as $name) {
            $this->get(route($name))->assertRedirect(route('leads.index'));
        }
        $this->get('/')->assertRedirect(route('leads.index'));
        $this->post(route('proposals.store'), [])->assertForbidden();
        $this->post(route('employees.store'), [])->assertForbidden();
    }

    public function test_new_leads_show_as_dashboard_cards_and_as_a_menu_shortcut_with_count(): void
    {
        $this->lead();
        $this->lead(['name' => 'João Souza', 'external_id' => 'x2', 'status' => 'contacted']);
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('Maria da Silva')->assertDontSee('João Souza')->assertSee('class="dash-lead"', false);
        $this->actingAs($this->attendant)->get(route('leads.index'))->assertOk()->assertSee('nav-shortcuts', false)->assertSee('<b class="nav-count">1</b>', false)->assertDontSee('Falta pagar');
    }

    public function test_lead_dashboard_shows_origin_and_conversion_without_test_leads(): void
    {
        $this->lead(['status' => 'converted', 'handled_by' => $this->attendant->id, 'contacted_at' => now()]);
        $this->lead(['external_id' => 'x2']);
        $this->lead(['campaign' => 'Black Friday', 'external_id' => 'x3', 'status' => 'contacted', 'extra' => ['especie_do_pet' => 'gato']]);
        $this->lead(['name' => '<test lead: dummy data for full_name>', 'source' => 'teste', 'campaign' => 'Teste do Facebook', 'external_id' => 't1']);
        $this->actingAs($this->attendant)->get(route('leads.dashboard'))->assertOk()
            ->assertSee('Black Friday')->assertDontSee('Teste do Facebook')
            ->assertSee('67%')->assertSee('33%')->assertSee('Gato')->assertSee('Ana Atendimento')
            ->assertSee('<strong>0 min</strong>', false);
        $this->actingAs($this->attendant)->get(route('leads.dashboard', ['dias' => 5]))->assertSessionHasErrors('dias');
    }

    public function test_new_leads_come_first_and_the_menu_count_is_never_stale(): void
    {
        $this->lead(['name' => 'Antigo Novo', 'external_id' => 'a1']);
        $this->lead(['name' => 'Recente Chamado', 'external_id' => 'a2', 'status' => 'contacted']);
        $this->actingAs($this->attendant)->get(route('leads.index'))->assertSeeInOrder(['Antigo Novo', 'Recente Chamado'])->assertSee('<b class="nav-count">1</b>', false);
        $this->lead(['name' => 'Chegou Agora', 'external_id' => 'a3']);
        $this->actingAs($this->attendant)->get(route('leads.index'))->assertSee('<b class="nav-count">2</b>', false);
    }

    public function test_panel_lists_who_waits_longest_and_compares_with_the_previous_period(): void
    {
        $older = $this->lead(['name' => 'Espera Muito', 'external_id' => 'b1']);
        $older->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();
        $this->lead(['name' => 'Espera Pouco', 'external_id' => 'b2']);
        $this->lead(['name' => 'Já Chamada', 'external_id' => 'b3', 'status' => 'contacted']);
        $page = $this->actingAs($this->attendant)->get(route('leads.dashboard'))->assertOk();
        $page->assertSeeInOrder(['Esperando há mais tempo', 'Espera Muito', 'Espera Pouco'])->assertDontSee('Já Chamada')->assertSee('+100% sobre os 30 dias anteriores');
    }

    public function test_list_shows_only_the_next_step_of_each_lead(): void
    {
        $lead = $this->lead(['external_id' => 'p1', 'campaign' => '120253596157660216', 'extra' => ['anuncio' => 'Plano Outubro', 'especie_do_pet' => 'cachorro']]);
        $list = fn () => $this->actingAs($this->attendant)->get(route('leads.index'));

        // O nome do anúncio diz mais que o número da campanha.
        $list()->assertSee('Esperando')->assertSee('Cachorro')->assertSee('Plano Outubro')->assertDontSee('Campanha nº')
            ->assertSee('Já chamei</button>', false)->assertDontSee('Virou cliente</button>', false);
        $lead->update(['status' => 'contacted']);
        $list()->assertSee('Virou cliente</button>', false)->assertSee('Sem interesse</button>', false)->assertDontSee('Já chamei</button>', false);
        $lead->update(['status' => 'converted']);
        $list()->assertSee('Reabrir</button>', false)->assertDontSee('Sem interesse</button>', false);
    }

    public function test_lead_data_shows_where_it_came_from_and_who_handled_it(): void
    {
        $lead = $this->lead(['external_id' => 'd1', 'pet_name' => 'Thor', 'extra' => ['platform' => 'ig', 'anuncio' => 'Vídeo gato', 'formulario' => 'Plano Econômico', 'especie_do_pet' => 'gato', 'idade_do_pet' => '3_anos']]);
        $list = fn () => $this->actingAs($this->attendant)->get(route('leads.index'));

        $list()->assertSee('data-lead-more="'.$lead->id.'"', false)
            ->assertSeeInOrder(['Rede', 'Instagram', 'Campanha', 'Outubro', 'Anúncio', 'Vídeo gato', 'Formulário', 'Plano Econômico', 'Pet', 'Thor', 'Espécie', 'Gato', 'Idade do pet', '3 anos', 'Chegou', 'Atendido por', 'Ninguém ainda'])
            ->assertDontSee('Platform')->assertDontSee('Última mudança');

        $this->actingAs($this->attendant)->post(route('leads.update', $lead), ['status' => 'contacted']);
        $handled = $lead->fresh();
        $time = fn ($date) => $date->timezone('America/Sao_Paulo')->format('d/m/Y H:i');
        $list()->assertSeeInOrder(['Atendido por', 'Ana Atendimento', 'Primeiro contato', $time($handled->contacted_at), 'Última mudança', $time($handled->updated_at)]);
    }

    public function test_numeric_campaign_id_never_shows_as_a_long_number(): void
    {
        $this->lead(['campaign' => '120253596157660216', 'external_id' => 'c1']);
        foreach (['leads.index', 'leads.dashboard'] as $route) {
            $this->actingAs($this->attendant)->get(route($route))->assertSee('Campanha nº …660216')->assertDontSee('120253596157660216');
        }
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee('Campanha nº …660216')->assertDontSee('120253596157660216');
    }

    public function test_panel_works_with_no_leads_at_all(): void
    {
        foreach ([7, 30, 90] as $days) {
            $this->actingAs($this->admin)->get(route('leads.dashboard', ['dias' => $days]))->assertOk()->assertSee('Nenhum lead recebido ainda')->assertSee('Ninguém esperando.');
        }
    }

    public function test_attendant_logs_in_straight_to_leads(): void
    {
        $this->post(route('authenticate'), ['email' => 'ana@example.test', 'password' => 'Example123!!!'])->assertRedirect(route('leads.index'));
    }

    public function test_only_test_leads_can_be_deleted_and_only_by_admins(): void
    {
        $real = $this->lead();
        $test = $this->lead(['name' => '<test lead: dummy data for full_name>', 'source' => 'teste', 'phone' => null, 'external_id' => 't1']);
        $this->actingAs($this->attendant)->post(route('leads.destroy', $test))->assertForbidden();
        $this->actingAs($this->admin)->post(route('leads.destroy', $real))->assertUnprocessable();
        $this->actingAs($this->admin)->post(route('leads.destroy', $test))->assertRedirect();
        $this->assertDatabaseMissing('leads', ['id' => $test->id]);
        $this->assertDatabaseHas('leads', ['id' => $real->id]);
    }

    public function test_admin_can_create_an_attendant_access_in_both_forms(): void
    {
        $this->actingAs($this->admin)->post(route('employees.store'), ['name' => 'Bia Atendimento', 'email' => 'bia@example.test', 'password' => 'Example123!!!', 'password_confirmation' => 'Example123!!!',
            'active' => 1, 'role' => 'attendant', 'franchise_ids' => [$this->unit->id]])->assertRedirect(route('employees.index'));
        $this->assertSame('attendant', User::where('email', 'bia@example.test')->value('role'));
        $this->actingAs($this->admin)->post(route('management.users.store'), ['name' => 'Carla Atendimento', 'email' => 'carla@example.test', 'password' => 'Example123!!!',
            'role' => 'attendant', 'franchise_ids' => [$this->unit->id]])->assertRedirect();
        $this->assertSame('attendant', User::where('email', 'carla@example.test')->value('role'));
    }
}

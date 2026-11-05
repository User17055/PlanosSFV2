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

    public function test_list_offers_every_status_on_each_lead(): void
    {
        $lead = $this->lead(['external_id' => 'p1', 'campaign' => '120253596157660216', 'extra' => ['anuncio' => 'Plano Outubro', 'especie_do_pet' => 'cachorro']]);
        $list = fn () => $this->actingAs($this->attendant)->get(route('leads.index'));

        // O nome do anúncio diz mais que o número da campanha.
        $list()->assertSee('Esperando')->assertSee('Cachorro')->assertSee('Plano Outubro')->assertDontSee('Campanha nº')
            ->assertSee('Abrir WhatsApp</a>', false)->assertDontSee('assets/brand/whatsapp', false)
            ->assertSeeInOrder(['Esperando</option>', 'Processando</option>', 'Finalizado</option>', 'Sem interesse</option>'], false)->assertSee('value="new" selected', false)
            // A bolinha da situação fica no canto direito da linha, junto da seta.
            ->assertSeeInOrder(['class="lead-origin-cell"', 'class="lead-corner"', 'lead-dot is-new', 'data-lead-toggle'], false);
        $lead->update(['status' => 'contacted']);
        $list()->assertSee('value="contacted" selected', false);
    }

    public function test_panel_filters_change_every_number(): void
    {
        $this->lead(['name' => 'Paga Google', 'external_id' => 'f1', 'status' => 'converted', 'handled_by' => $this->attendant->id, 'extra' => ['plataforma' => 'google']]);
        $this->lead(['name' => 'Organica Site', 'external_id' => 'f2', 'source' => 'site_whatsapp', 'extra' => ['plataforma' => 'site', 'trafego' => 'organico']]);
        $old = $this->lead(['name' => 'Organica Antiga', 'external_id' => 'f3', 'source' => 'site_whatsapp', 'extra' => ['plataforma' => 'google', 'trafego' => 'organico']]);
        $old->forceFill(['created_at' => now()->subDays(20)])->saveQuietly();
        $panel = fn (array $filters = []) => $this->actingAs($this->attendant)->get(route('leads.dashboard', $filters))->assertOk();
        $total = fn (int $expected) => fn ($totals) => $totals['total'] === $expected;

        $panel()->assertViewHas('totals', $total(3))->assertViewHas('waitingTotal', 2)->assertSee('Quem converteu');
        $panel(['trafego' => 'Pago'])->assertViewHas('totals', $total(1))->assertViewHas('waitingTotal', 0)->assertSee('Paga Google')->assertSee('Ana Atendimento');
        $panel(['trafego' => 'Orgânico'])->assertViewHas('totals', $total(2))->assertViewHas('waitingTotal', 2)->assertDontSee('Quem converteu');
        $panel(['origem' => 'Google', 'trafego' => 'Orgânico'])->assertViewHas('totals', $total(1))->assertViewHas('waitingTotal', 1);
        $panel(['conversao' => 'Ainda não converteu'])->assertViewHas('totals', $total(2));
        $panel(['responsavel' => 'Ana Atendimento'])->assertViewHas('totals', $total(1));
        $panel(['de' => now()->subDays(25)->format('Y-m-d'), 'ate' => now()->subDays(10)->format('Y-m-d')])->assertViewHas('totals', $total(1))->assertViewHas('days', 16)->assertViewHas('endsToday', false);
        // Datas invertidas valem como período, e um filtro sem lead continua aparecendo como escolhido.
        $panel(['de' => now()->subDays(10)->format('Y-m-d'), 'ate' => now()->subDays(25)->format('Y-m-d')])->assertViewHas('totals', $total(1));
        $panel(['campanha' => 'Campanha que sumiu'])->assertViewHas('totals', $total(0))->assertSee('Nenhum lead com esses filtros.')->assertSee('<option value="Campanha que sumiu" selected>', false);
    }

    public function test_list_filters_by_period_conversion_and_traffic(): void
    {
        $this->lead(['name' => 'Lead Esperando', 'external_id' => 'g1', 'source' => 'site_whatsapp', 'extra' => ['plataforma' => 'google', 'trafego' => 'organico']]);
        $this->lead(['name' => 'Lead Processando', 'external_id' => 'g2', 'status' => 'contacted']);
        $this->lead(['name' => 'Lead Finalizado', 'external_id' => 'g3', 'status' => 'converted']);
        $lost = $this->lead(['name' => 'Lead Perdido', 'external_id' => 'g4', 'status' => 'lost']);
        $lost->forceFill(['created_at' => now()->subDays(20)])->saveQuietly();
        $list = fn (array $filters) => $this->actingAs($this->attendant)->get(route('leads.index', $filters))->assertOk();

        $list(['conversao' => 'Ainda não converteu'])->assertSee('Lead Esperando')->assertSee('Lead Processando')->assertDontSee('Lead Finalizado')->assertDontSee('Lead Perdido')->assertSee('limpar filtros');
        $list(['trafego' => 'Orgânico'])->assertSee('Lead Esperando')->assertDontSee('Lead Processando');
        $list(['dias' => 7])->assertSee('Lead Finalizado')->assertDontSee('Lead Perdido');
        $list(['de' => now()->subDays(25)->format('Y-m-d'), 'ate' => now()->subDays(15)->format('Y-m-d')])->assertSee('Lead Perdido')->assertDontSee('Lead Finalizado');
        // A situação e a busca somam com os filtros, e os links mantêm o que já foi escolhido.
        $list(['conversao' => 'Ainda não converteu', 'situacao' => 'contacted'])->assertSee('Lead Processando')->assertDontSee('Lead Esperando');
        $list(['trafego' => 'Pago', 'dias' => 7])->assertSee('situacao=new&amp;dias=7&amp;trafego=Pago', false);
        $this->actingAs($this->attendant)->get(route('leads.index', ['de' => 'ontem']))->assertSessionHasErrors('de');
    }

    private function teammate(string $name, string $role = 'attendant'): User
    {
        $user = User::create(['name' => $name, 'email' => mb_strtolower(strtok($name, ' ')).'@example.test', 'password' => 'Example123!!!', 'role' => $role, 'active' => true, 'franchise_id' => $this->unit->id]);
        $user->franchises()->sync([$this->unit->id => ['active' => true]]);
        return $user;
    }

    public function test_each_person_sees_only_their_leads_and_the_queue(): void
    {
        $bia = $this->teammate('Bia Vendas');
        $this->lead(['name' => 'Lead Da Ana', 'external_id' => 'o1', 'status' => 'contacted', 'handled_by' => $this->attendant->id]);
        $this->lead(['name' => 'Lead Da Bia', 'external_id' => 'o2', 'status' => 'contacted', 'handled_by' => $bia->id]);
        $this->lead(['name' => 'Lead Da Fila', 'external_id' => 'o3']);

        $this->actingAs($this->attendant)->get(route('leads.index'))->assertSee('Lead Da Ana')->assertSee('Lead Da Fila')->assertDontSee('Lead Da Bia')->assertDontSee('Com quem está')->assertDontSee('Bia Vendas');
        $this->actingAs($this->attendant)->get(route('leads.dashboard'))->assertViewHas('totals', fn ($totals) => $totals['total'] === 2)->assertDontSee('Lead Da Bia')->assertDontSee('Com quem está');
        // Nem pelo filtro nem pelo endereço direto se chega ao lead de outra pessoa.
        $this->actingAs($this->attendant)->get(route('leads.index', ['responsavel' => 'Bia Vendas']))->assertDontSee('Lead Da Bia');
        $this->actingAs($this->attendant)->post(route('leads.update', Lead::where('name', 'Lead Da Bia')->sole()), ['status' => 'lost'])->assertForbidden();
        $this->assertSame('contacted', Lead::where('name', 'Lead Da Bia')->sole()->status);

        foreach ([$this->admin, $this->teammate('Gabi Gestora', 'manager')] as $boss) {
            $this->actingAs($boss)->get(route('leads.index'))->assertSee('Lead Da Ana')->assertSee('Lead Da Bia')->assertSee('Lead Da Fila')->assertSee('Com quem está')->assertSee('<option value="'.$bia->id.'" selected>Bia Vendas</option>', false)
                ->assertSeeInOrder(['<th>Anúncio</th>', '<th>Com quem está</th>', '<th>Situação</th>', 'class="lead-tags"', 'class="lead-owner"', 'class="lead-step"'], false);
            $this->actingAs($boss)->get(route('leads.dashboard'))->assertViewHas('totals', fn ($totals) => $totals['total'] === 3)->assertSee('Com quem está');
        }
    }

    public function test_first_to_act_on_a_queued_lead_keeps_it_and_the_menu_follows(): void
    {
        $bia = $this->teammate('Bia Vendas');
        $lead = $this->lead(['name' => 'Lead Da Fila', 'external_id' => 'q1']);
        $this->actingAs($this->attendant)->get(route('leads.index'))->assertSee('<b class="nav-count">1</b>', false);

        // Olhar ou devolver para "Esperando" não pega o lead.
        $this->actingAs($bia)->post(route('leads.update', $lead), ['status' => 'new'])->assertRedirect();
        $this->assertNull($lead->fresh()->handled_by);

        $this->actingAs($this->attendant)->post(route('leads.update', $lead), ['status' => 'contacted'])->assertRedirect();
        $this->assertSame($this->attendant->id, $lead->fresh()->handled_by);
        $this->actingAs($bia)->post(route('leads.update', $lead), ['status' => 'lost'])->assertForbidden();
        $this->actingAs($bia)->get(route('leads.index'))->assertDontSee('Lead Da Fila');

        // O administrador muda a situação sem tirar o lead de quem está com ele.
        $this->actingAs($this->admin)->post(route('leads.update', $lead), ['status' => 'converted'])->assertRedirect();
        $this->assertSame(['converted', $this->attendant->id], [$lead->fresh()->status, $lead->fresh()->handled_by]);
    }

    public function test_only_administrators_choose_who_keeps_a_lead(): void
    {
        $bia = $this->teammate('Bia Vendas');
        $lead = $this->lead(['name' => 'Lead Da Fila', 'external_id' => 'a1', 'handled_by' => $this->attendant->id]);
        $assign = fn (User $user, $to) => $this->actingAs($user)->post(route('leads.assign', $lead), ['handled_by' => $to]);

        $assign($this->attendant, $bia->id)->assertForbidden();
        $this->assertSame($this->attendant->id, $lead->fresh()->handled_by);

        $assign($this->teammate('Gabi Gestora', 'manager'), $bia->id)->assertRedirect();
        $this->assertSame($bia->id, $lead->fresh()->handled_by);
        $assign($this->admin, '')->assertRedirect();
        $this->assertNull($lead->fresh()->handled_by);

        // Só vale para quem é da equipe, está ativo e tem acesso à unidade.
        $other = Franchise::create(['name' => 'Outra', 'code' => 'OUTRA', 'active' => true]);
        $outsider = $this->teammate('Otto Fora');
        $outsider->franchises()->sync([$other->id => ['active' => true]]);
        $tutor = $this->teammate('Tina Tutora', 'customer');
        $off = $this->teammate('Ivo Inativo');
        $off->update(['active' => false]);
        foreach ([$outsider, $tutor, $off] as $invalid) {
            $assign($this->admin, $invalid->id)->assertSessionHasErrors('handled_by');
        }
        $this->assertNull($lead->fresh()->handled_by);
    }

    public function test_manager_job_roles_that_update_leads_can_assign_them_after_the_migration(): void
    {
        $withLeads = \App\Models\JobRole::create(['name' => 'Recepção', 'base_role' => 'manager', 'permissions' => ['leads.view', 'leads.update']]);
        $withoutLeads = \App\Models\JobRole::create(['name' => 'Financeiro', 'base_role' => 'manager', 'permissions' => ['customers.view']]);
        (require database_path('migrations/2026_11_05_000001_grant_lead_assign_to_manager_job_roles.php'))->up();
        $this->assertTrue($withLeads->fresh()->allows('leads.assign'));
        $this->assertFalse($withoutLeads->fresh()->allows('leads.assign'));
    }

    public function test_going_back_to_waiting_never_counts_as_first_contact(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->attendant)->post(route('leads.update', $lead), ['status' => 'new'])->assertRedirect();
        $this->assertNull($lead->fresh()->contacted_at);
        $this->actingAs($this->attendant)->post(route('leads.update', $lead), ['status' => 'lost'])->assertRedirect();
        $this->assertSame('lost', $lead->fresh()->status);
        $this->assertNotNull($lead->fresh()->contacted_at);
    }

    public function test_lead_data_shows_where_it_came_from_and_who_handled_it(): void
    {
        $lead = $this->lead(['external_id' => 'd1', 'pet_name' => 'Thor', 'extra' => ['platform' => 'ig', 'anuncio' => 'Vídeo gato', 'formulario' => 'Plano Econômico', 'especie_do_pet' => 'gato', 'idade_do_pet' => '3_anos']]);
        $list = fn () => $this->actingAs($this->attendant)->get(route('leads.index'));

        $list()->assertSee('data-lead-more="'.$lead->id.'"', false)
            ->assertSeeInOrder(['Rede', 'Instagram', 'Campanha', 'Outubro', 'Anúncio', 'Vídeo gato', 'Formulário', 'Plano Econômico', 'Pet', 'Thor', 'Chegou', 'Atendido por', 'Ninguém ainda'])
            // O MySQL guarda as chaves do JSON na ordem dele: entre as respostas, só importa cada uma com o seu valor.
            ->assertSeeInOrder(['Espécie', 'Gato'])->assertSeeInOrder(['Idade do pet', '3 anos'])
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

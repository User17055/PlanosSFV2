<?php

namespace Tests\Feature;

use App\Models\{Franchise,Lead,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Lead do anúncio (Pluga) entra por um endereço secreto e aparece para a equipe, sem duplicar. */
class LeadIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.leads.token' => 'segredo-de-teste', 'services.leads.franchise_code' => 'CENTRAL']);
        Franchise::create(['name' => 'Canal central', 'code' => 'CENTRAL', 'active' => true]);
    }

    private function send(array $payload, string $token = 'segredo-de-teste')
    {
        return $this->postJson('/webhooks/leads/'.$token, $payload);
    }

    public function test_wrong_or_missing_token_records_nothing(): void
    {
        $this->send(['nome' => 'Maria', 'telefone' => '18999990000'], 'errado')->assertNotFound();
        config(['services.leads.token' => '']);
        $this->send(['nome' => 'Maria', 'telefone' => '18999990000'], 'qualquer')->assertNotFound();
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_lead_is_saved_with_clean_phone_and_never_duplicated(): void
    {
        $payload = ['full_name' => 'Maria da Silva', 'phone_number' => 'p:+55 (18) 99999-0000', 'email' => 'maria@example.test', 'lead_id' => 'fb-123', 'pet' => 'Lua', 'form_name' => 'Plano Econômico', 'cidade' => 'Prudente'];
        $this->send($payload)->assertCreated();
        $this->send($payload)->assertOk()->assertJsonPath('duplicate', true);
        $lead = Lead::sole();
        $this->assertSame('5518999990000', $lead->phone);
        $this->assertSame('Lua', $lead->pet_name);
        $this->assertSame('Plano Econômico', $lead->campaign);
        $this->assertSame(['cidade' => 'Prudente'], $lead->extra);
        $this->assertSame('https://wa.me/5518999990000', $lead->whatsappUrl());
    }

    public function test_lead_without_contact_is_refused_and_repeat_without_id_inside_ten_minutes_is_ignored(): void
    {
        $this->send(['nome' => 'Sem contato'])->assertUnprocessable();
        $this->send(['nome' => 'João', 'telefone' => '18988887777'])->assertCreated();
        $this->send(['nome' => 'João', 'telefone' => '18988887777'])->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_facebook_test_lead_with_dummy_text_is_accepted_and_marked_as_test(): void
    {
        $this->send(['nome' => '<test lead: dummy data for full_name>', 'telefone' => '<test lead: dummy data for phone_number>', 'lead_id' => '1434711191471404'])->assertCreated();
        $lead = Lead::sole();
        $this->assertSame('teste', $lead->source);
        $this->assertNull($lead->phone);
        $this->send(['nome' => 'Real', 'telefone' => '<sem número>'])->assertUnprocessable();
    }

    public function test_staff_sees_and_updates_leads_but_customers_and_other_units_do_not(): void
    {
        $this->send(['nome' => 'Maria Lead', 'telefone' => '18999990000', 'idade_do_pet' => '3 anos'])->assertCreated();
        $lead = Lead::sole();

        $this->actingAs(User::factory()->create(['role' => 'customer', 'active' => true, 'email_verified_at' => now()]))->get('/leads')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'sales', 'active' => true]))->get('/leads')->assertOk()->assertDontSee('Maria Lead');

        $admin = User::factory()->create(['role' => 'central_admin', 'active' => true]);
        $this->actingAs($admin)->get('/leads')->assertOk()->assertSee('Maria Lead')->assertSee('Idade do pet')->assertSee('3 anos')->assertSee('wa.me/5518999990000', false);
        $this->actingAs($admin)->post('/leads/'.$lead->id, ['status' => 'contacted'])->assertRedirect();
        $this->assertSame('contacted', $lead->fresh()->status);
        $this->assertSame($admin->id, $lead->fresh()->handled_by);
        $this->actingAs($admin)->post('/leads/'.$lead->id, ['status' => 'qualquer'])->assertSessionHasErrors('status');
    }

    public function test_form_questions_with_other_names_still_become_a_lead(): void
    {
        $this->send(['Nome completo' => 'Maria da Silva', 'Qual o seu WhatsApp?' => '(18) 99999-0000', 'Espécie do Pet' => 'Gato', 'E-mail' => 'maria@example.test'])->assertCreated();
        $lead = Lead::sole();
        $this->assertSame('Maria da Silva', $lead->name);
        $this->assertSame('18999990000', $lead->phone);
        $this->assertSame('maria@example.test', $lead->email);
        $this->assertSame(['especie_do_pet' => 'Gato'], $lead->extra);
    }

    public function test_list_shows_the_network_the_lead_came_from(): void
    {
        $this->send(['nome' => 'Ana', 'telefone' => '18911111111', 'platform' => 'ig'])->assertCreated();
        $this->send(['nome' => 'Bia', 'telefone' => '18922222222', 'Plataforma' => 'FB'])->assertCreated();
        $this->send(['nome' => 'Cris', 'telefone' => '18933333333', 'origem' => 'instagram_stories'])->assertCreated();
        $this->send(['nome' => 'Davi', 'telefone' => '18944444444'])->assertCreated();
        $this->send(['nome' => 'Eva', 'telefone' => '18955555555', 'origem' => 'Google Ads'])->assertCreated();
        $this->assertSame(['Instagram', 'Facebook', 'Instagram Stories', null, 'Google Ads'], Lead::orderBy('id')->get()->map->channel()->all());
        // Sem o campo, a tela diz o que se sabe, sem escolher uma das redes.
        $this->assertSame(['label' => 'Facebook ou Instagram', 'logos' => ['facebook', 'instagram']], Lead::where('name', 'Davi')->sole()->origin());

        $admin = User::factory()->create(['role' => 'central_admin', 'active' => true]);
        // A rede aparece com a logo e não se repete nos detalhes do lead.
        $this->actingAs($admin)->get(route('leads.index'))->assertOk()->assertSee('assets/brand/instagram.svg', false)->assertSee('assets/brand/facebook.svg', false)->assertSee('assets/brand/google.svg', false)->assertSee('assets/brand/whatsapp.png', false)->assertSee('Instagram Stories')->assertDontSee('Platform:')->assertDontSee('Origem:');
    }

    public function test_campaign_name_wins_over_its_number_and_tells_the_network(): void
    {
        $name = '[Plano de Saúde] - [20,00/dia] - [P. Prud] - [M&F] - [Reels, stories e Feed IG] - [Form. Inst.] - [25-54 anos]';
        $this->send(['nome' => 'Ana', 'telefone' => '18911111111', 'campanha' => '120253596157660216', 'campaign_name' => $name])->assertCreated();
        $lead = Lead::sole();
        $this->assertSame($name, $lead->campaign);
        $this->assertSame('Plano de Saúde · 20,00/dia · P. Prud · M&F · Reels, stories e Feed IG · Form. Inst. · 25-54 anos', $lead->campaignLabel());
        $this->assertSame(['label' => 'Instagram', 'logos' => ['instagram']], $lead->origin());

        $origin = fn (string $campaign, ?array $extra = null) => (new Lead(['campaign' => $campaign, 'source' => 'facebook_lead_ads', 'extra' => $extra]))->origin()['label'];
        $this->assertSame('Facebook', $origin('[Feed FB]'));
        $this->assertSame('Instagram', $origin('Outubro', ['anuncio' => 'Vídeo Instagram']));
        $this->assertSame('Facebook ou Instagram', $origin('[Feed IG e FB]'));
        // "Inst." é formulário instantâneo, e "big" tem as letras de IG, mas nada disso aponta a rede.
        foreach (['[Form. Inst.]', 'Big Friday', '120253596157660216'] as $unknown) {
            $this->assertSame('Facebook ou Instagram', $origin($unknown), $unknown);
        }
        // O campo da rede, quando vem, manda mais que o nome da campanha.
        $this->assertSame('Facebook', (new Lead(['campaign' => '[Feed IG]', 'source' => 'facebook_lead_ads', 'extra' => ['platform' => 'fb']]))->origin()['label']);

        $admin = User::factory()->create(['role' => 'central_admin', 'active' => true]);
        $this->actingAs($admin)->get(route('leads.index'))->assertOk()->assertSee('Veio de: Instagram')->assertSee('Plano de Saúde · 20,00/dia')->assertDontSee('[Plano de Saúde]');
    }

    public function test_nested_payload_is_read(): void
    {
        $this->send(['lead' => ['nome' => 'Ana', 'telefone' => '18977776666'], 'origem' => ['campanha' => 'Outubro']])->assertCreated();
        $lead = Lead::sole();
        $this->assertSame(['Ana', '18977776666', 'Outubro'], [$lead->name, $lead->phone, $lead->campaign]);
    }

    public function test_repeated_id_with_another_contact_is_a_new_lead_not_a_resend(): void
    {
        $this->send(['id' => 'form-fixo', 'nome' => 'Ana', 'telefone' => '18911111111'])->assertCreated();
        $this->send(['id' => 'form-fixo', 'nome' => 'Bia', 'telefone' => '18922222222'])->assertCreated();
        $this->send(['id' => 'form-fixo', 'nome' => 'Bia', 'telefone' => '18922222222'])->assertOk()->assertJsonPath('duplicate', true);
        $this->send(['id' => 'form-fixo', 'nome' => 'Ana', 'telefone' => '18911111111'])->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(['Ana', 'Bia'], Lead::orderBy('id')->pluck('name')->all());
    }

    public function test_refused_delivery_shows_field_names_to_admins_but_never_the_values(): void
    {
        $this->send(['nome' => 'Segredo da Silva', 'contato_preferido' => 'manhã'])->assertUnprocessable();
        $admin = User::factory()->create(['role' => 'central_admin', 'active' => true]);
        foreach (['leads.index', 'leads.dashboard'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk()->assertSee('contato_preferido')->assertDontSee('Segredo da Silva')->assertDontSee('manhã');
        }
        $attendant = User::factory()->create(['role' => 'attendant', 'active' => true]);
        $this->actingAs($attendant)->get(route('leads.index'))->assertOk()->assertDontSee('contato_preferido');
    }

    public function test_panel_warns_admin_when_receiving_is_turned_off(): void
    {
        config(['services.leads.token' => '']);
        $admin = User::factory()->create(['role' => 'central_admin', 'active' => true]);
        $this->actingAs($admin)->get(route('leads.index'))->assertSee('Recebimento desligado');
        config(['services.leads.token' => 'segredo-de-teste']);
        $this->actingAs($admin)->get(route('leads.index'))->assertDontSee('Recebimento desligado');
    }
}

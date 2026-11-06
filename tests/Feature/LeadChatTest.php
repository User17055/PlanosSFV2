<?php

namespace Tests\Feature;

use App\Models\{DigisacConnection, Franchise, JobRole, Lead, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\{Cache, DB, Http, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class LeadChatTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;
    private User $bia;
    private User $admin;
    private Franchise $unit;
    private Lead $mine;
    private Lead $theirs;
    private Lead $queue;
    private string $base = 'https://saofranciscopet.digisac.chat';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->unit = Franchise::create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $this->ana = $this->user('Ana');
        $this->bia = $this->user('Bia');
        $this->admin = $this->user('Admin', 'central_admin');
        $this->mine = $this->lead('Cliente da Ana', $this->ana);
        $this->theirs = $this->lead('Cliente da Bia', $this->bia);
        $this->queue = $this->lead('Cliente disponível');
    }

    private function user(string $name, string $role = 'attendant'): User
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.test', 'password' => 'Example123!!!', 'role' => $role, 'active' => true]);
        $user->franchises()->sync([$this->unit->id => ['active' => true]]);
        return $user;
    }

    private function lead(string $name, ?User $owner = null): Lead
    {
        return Lead::create(['name' => $name, 'originating_franchise_id' => $this->unit->id, 'phone' => '18999990000', 'handled_by' => $owner?->id]);
    }

    private function connection(User $user, string $token = 'token-secreto-ana'): DigisacConnection
    {
        return DigisacConnection::create(['user_id' => $user->id, 'base_url' => $this->base, 'api_token' => $token,
            'digisac_user_id' => 'remote-'.$user->id, 'service_id' => 'service-1', 'verified_at' => now(), 'remote_name' => $user->name]);
    }

    private function contact(): array
    {
        return ['id' => 'contact-1', 'serviceId' => 'service-1', 'data' => ['number' => '5518999990000']];
    }

    private function message(array $overrides = []): array
    {
        return $overrides + ['id' => 'm1', 'contactId' => 'contact-1', 'serviceId' => 'service-1', 'visible' => true,
            'type' => 'chat', 'text' => 'Mensagem do cliente', 'timestamp' => '2026-11-05T14:00:00Z', 'isFromMe' => false];
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + ['text' => 'Olá, posso ajudar?', 'request_id' => (string) Str::uuid()];
    }

    public function test_floating_chat_is_available_only_to_staff_with_lead_access(): void
    {
        $this->actingAs($this->ana)->get(route('leads.index'))->assertOk()
            ->assertSee('data-chat-launcher', false)->assertSee('data-chat-frame', false)
            ->assertSee('href="'.route('leads.chat.show', $this->mine).'" data-chat-open', false);
        $this->get(route('leads.chat.index'))->assertOk()->assertDontSee('data-chat-launcher', false)
            ->assertDontSee('class="sidebar', false)->assertSee('chat-window-content');
        $finance = $this->user('Financeiro', 'finance');
        $this->actingAs($finance)->get(route('dashboard'))->assertOk()->assertDontSee('data-chat-launcher', false);
        $manager = $this->user('Gestora', 'manager');
        $manager->update(['areas' => ['dashboard']]);
        $this->actingAs($manager)->get(route('dashboard'))->assertOk()->assertDontSee('data-chat-launcher', false);
        Http::assertNothingSent();
    }

    public function test_chat_button_shows_the_red_dot_only_while_a_lead_is_waiting(): void
    {
        $this->actingAs($this->ana)->get(route('leads.index'))->assertOk()->assertSee('data-chat-dot aria-hidden', false)->assertSee('há leads esperando atendimento');
        Lead::query()->update(['status' => 'contacted']);
        $this->get(route('leads.index'))->assertOk()->assertSee('data-chat-dot hidden', false)->assertDontSee('há leads esperando atendimento');
        // Quem atende só conta o que pode ver: lead esperando com outra pessoa não acende a bolinha.
        $this->theirs->update(['status' => 'new']);
        $this->get(route('leads.index'))->assertOk()->assertSee('data-chat-dot hidden', false);
        $this->actingAs($this->admin)->get(route('leads.index'))->assertOk()->assertSee('data-chat-dot aria-hidden', false);
    }

    public function test_each_person_lists_their_leads_and_only_administrators_list_everyone(): void
    {
        $this->actingAs($this->ana)->get(route('leads.chat.index'))->assertOk()->assertSee('Cliente da Ana')->assertDontSee('Cliente da Bia')->assertDontSee('Cliente disponível');
        $this->get(route('leads.chat.index', ['fila' => 1]))->assertOk()->assertSee('Cliente disponível')->assertDontSee('Cliente da Ana')->assertDontSee('Cliente da Bia');
        $this->get(route('leads.chat.index', ['q' => 'Bia']))->assertOk()->assertDontSee('Cliente da Bia');
        // Quem não é administrador não chega à aba Todas nem pelo endereço.
        $this->get(route('leads.chat.index', ['todas' => 1]))->assertOk()->assertDontSee('Cliente da Bia')->assertSee('Cliente da Ana')->assertDontSee('>Todas</a>', false);
        $this->actingAs($this->admin)->get(route('leads.chat.index'))->assertOk()->assertDontSee('Cliente da Ana')->assertDontSee('Cliente da Bia')->assertSee('>Todas</a>', false);
        $this->get(route('leads.chat.index', ['todas' => 1]))->assertOk()->assertSee('Cliente da Ana')->assertSee('Cliente da Bia')->assertSee('Com Ana')->assertSee('Com Bia')->assertDontSee('Cliente disponível');
        $this->get(route('leads.chat.show', $this->theirs))->assertOk();
        Http::assertNothingSent();
    }

    public function test_administrator_reads_any_conversation_with_their_own_token_but_never_replies(): void
    {
        $manager = $this->user('Gestora', 'manager');
        $this->getJson(route('leads.chat.messages', $this->theirs))->assertUnauthorized();
        // Sem conexão própria não lê: o token do atendente nunca é usado por outra pessoa.
        $this->connection($this->bia, 'token-da-bia');
        $this->actingAs($this->admin)->getJson(route('leads.chat.messages', $this->theirs))->assertStatus(422);
        Http::assertNothingSent();

        $this->connection($this->admin, 'token-do-admin');
        $this->connection($manager, 'token-da-gestora');
        Http::fake([
            $this->base.'/api/v1/contacts*' => Http::response(['data' => [$this->contact()]]),
            $this->base.'/api/v1/messages*' => Http::response(['data' => [$this->message()]]),
        ]);
        foreach ([[$this->admin, 'token-do-admin'], [$manager, 'token-da-gestora']] as [$boss, $token]) {
            $this->actingAs($boss)->getJson(route('leads.chat.messages', $this->theirs))->assertOk()->assertJsonPath('messages.0.text', 'Mensagem do cliente');
            Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.$token));
            $this->get(route('leads.chat.show', $this->theirs))->assertOk()->assertSee('Você está acompanhando esta conversa')->assertSee('Com Bia')->assertDontSee('data-chat-compose', false);
            $this->postJson(route('leads.chat.send', $this->theirs), $this->payload())->assertForbidden();
            // Lead da fila continua precisando ser assumido antes de abrir a conversa.
            $this->getJson(route('leads.chat.messages', $this->queue))->assertForbidden();
        }
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        Http::assertNotSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer token-da-bia'));

        // Gestão só enxerga a própria unidade.
        $otherUnit = Franchise::create(['name' => 'Outra', 'code' => 'OUTRA', 'active' => true]);
        $this->theirs->update(['originating_franchise_id' => $otherUnit->id]);
        $this->actingAs($manager)->getJson(route('leads.chat.messages', $this->theirs))->assertForbidden();
        $this->get(route('leads.chat.show', $this->theirs))->assertForbidden();
    }

    public function test_other_owners_and_units_are_blocked_before_any_external_request(): void
    {
        $this->connection($this->ana);
        $otherUnit = Franchise::create(['name' => 'Outra', 'code' => 'OUTRA', 'active' => true]);
        $outside = $this->lead('Fora da unidade', $this->ana);
        $outside->update(['originating_franchise_id' => $otherUnit->id]);
        foreach ([$this->theirs, $outside] as $lead) {
            $this->actingAs($this->ana)->get(route('leads.chat.show', $lead))->assertForbidden();
            $this->getJson(route('leads.chat.messages', $lead))->assertForbidden();
            $this->postJson(route('leads.chat.send', $lead), $this->payload())->assertForbidden();
            $this->post(route('leads.chat.claim', $lead))->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_queue_requires_explicit_claim_and_two_people_cannot_claim_the_same_lead(): void
    {
        $this->actingAs($this->ana)->get(route('leads.chat.show', $this->queue))->assertOk()->assertSee('Assumir e abrir conversa');
        $this->getJson(route('leads.chat.messages', $this->queue))->assertForbidden();
        $this->postJson(route('leads.chat.send', $this->queue), $this->payload())->assertForbidden();
        $this->assertNull($this->queue->fresh()->handled_by);
        $this->post(route('leads.chat.claim', $this->queue))->assertRedirect(route('leads.chat.show', $this->queue));
        $this->assertSame($this->ana->id, $this->queue->fresh()->handled_by);
        $this->actingAs($this->bia)->post(route('leads.chat.claim', $this->queue))->assertForbidden();
        $this->assertSame($this->ana->id, $this->queue->fresh()->handled_by);
        Http::assertNothingSent();
    }

    public function test_history_is_filtered_in_both_directions_without_exposing_raw_api_data(): void
    {
        $this->connection($this->ana);
        Http::fake([
            $this->base.'/api/v1/contacts*' => Http::response(['data' => [$this->contact(), ['id' => 'wrong', 'serviceId' => 'other', 'data' => ['number' => '5518999990000']]]]),
            $this->base.'/api/v1/messages*' => Http::response(['data' => [
                $this->message(['id' => 'm2', 'isFromMe' => true, 'text' => '<script>alert(1)</script>', 'data' => ['private' => 'segredo']]),
                $this->message(['id' => 'hidden', 'visible' => false, 'text' => 'invisível']),
                $this->message(['id' => 'other', 'contactId' => 'another', 'text' => 'outra conversa']),
                $this->message(['id' => 'service', 'serviceId' => 'another', 'text' => 'outro canal']),
                $this->message(['id' => 'note', 'type' => 'note', 'text' => 'nota privada']),
                $this->message(),
            ]]),
        ]);
        $response = $this->actingAs($this->ana)->getJson(route('leads.chat.messages', $this->mine))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonCount(2, 'messages')->assertJsonPath('messages.0.id', 'm1')->assertJsonPath('messages.1.outgoing', true);
        $response->assertDontSee('segredo')->assertDontSee('outra conversa')->assertDontSee('nota privada')->assertDontSee('token-secreto');
        Http::assertSent(function (HttpRequest $request) {
            if (! str_contains($request->url(), '/messages')) return false;
            $query = json_decode($request['query'], true);
            return $query['where'] === ['contactId' => 'contact-1', 'serviceId' => 'service-1', 'visible' => true]
                && $request->hasHeader('Authorization', 'Bearer token-secreto-ana');
        });
    }

    public function test_send_uses_server_side_recipient_and_sender_and_deduplicates_requests(): void
    {
        $this->connection($this->ana);
        $this->connection($this->bia, 'token-da-bia');
        Http::fake([
            $this->base.'/api/v1/contacts*' => Http::response(['data' => [$this->contact()]]),
            $this->base.'/api/v1/messages' => Http::response(['id' => 'sent-message'], 201),
        ]);
        $payload = $this->payload(['contactId' => 'victim', 'userId' => 'other-user', 'serviceId' => 'other-service', 'number' => '5511111111111']);
        $this->actingAs($this->ana)->postJson(route('leads.chat.send', $this->mine), $payload)->assertOk()->assertJson(['sent' => true]);
        $this->postJson(route('leads.chat.send', $this->mine), $payload)->assertOk();
        $this->assertSame('contacted', $this->mine->fresh()->status);
        $this->assertNotNull($this->mine->fresh()->contacted_at);
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST'
            && $request->data() === ['text' => 'Olá, posso ajudar?', 'type' => 'chat', 'userId' => 'remote-'.$this->ana->id, 'origin' => 'user', 'contactId' => 'contact-1']
            && $request->hasHeader('Authorization', 'Bearer token-secreto-ana'));
        Http::assertSentCount(2);
    }

    public function test_first_contact_can_be_messaged_by_phone_without_listing_unrelated_conversations(): void
    {
        $this->connection($this->ana);
        Http::fake([
            $this->base.'/api/v1/contacts*' => Http::response(['data' => []]),
            $this->base.'/api/v1/messages' => Http::response(['id' => 'sent'], 201),
        ]);
        $this->actingAs($this->ana)->getJson(route('leads.chat.messages', $this->mine))->assertOk()->assertJsonCount(0, 'messages');
        $this->postJson(route('leads.chat.send', $this->mine), $this->payload())->assertOk();
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST'
            && $request['number'] === '5518999990000' && $request['serviceId'] === 'service-1' && ! isset($request['contactId']));
    }

    public function test_transfer_revokes_history_and_send_access_immediately(): void
    {
        $this->connection($this->ana);
        $this->mine->update(['handled_by' => $this->bia->id]);
        $this->actingAs($this->ana)->getJson(route('leads.chat.messages', $this->mine))->assertForbidden();
        $this->postJson(route('leads.chat.send', $this->mine), $this->payload())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_history_brings_contact_photo_and_files_only_as_https_links(): void
    {
        $this->connection($this->ana);
        $photo = 'https://storage.example.test/thumb.png?X-Amz-Signature=abc';
        Http::fake([
            $this->base.'/api/v1/contacts*' => Http::response(['data' => [$this->contact() + ['thumbAvatar' => ['url' => $photo, 'accountId' => 'segredo-conta']]]]),
            $this->base.'/api/v1/messages*' => Http::response(['data' => [
                $this->message(['id' => 'img', 'type' => 'image', 'text' => '', 'file' => ['url' => 'https://storage.example.test/foto.jpg', 'name' => 'foto.jpg', 'checksum' => 'interno']]),
                $this->message(['id' => 'bad', 'type' => 'document', 'file' => ['url' => 'javascript:alert(1)', 'name' => 'x.pdf']]),
            ]]),
        ]);
        $response = $this->actingAs($this->ana)->getJson(route('leads.chat.messages', $this->mine))->assertOk()
            ->assertJsonPath('avatar', $photo)->assertJsonPath('messages.1.file', ['url' => 'https://storage.example.test/foto.jpg', 'name' => 'foto.jpg'])
            ->assertJsonPath('messages.0.file', null);
        $response->assertDontSee('segredo-conta')->assertDontSee('interno')->assertDontSee('javascript');
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/messages') && json_decode($request['query'], true)['include'] === ['file']);
    }

    public function test_contact_photo_is_saved_with_the_lead_and_shown_only_to_who_sees_it(): void
    {
        Storage::fake('local');
        $this->connection($this->ana);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        Http::fake([
            $this->base.'/api/v1/contacts*' => Http::response(['data' => [$this->contact() + ['thumbAvatar' => ['url' => 'https://storage.example.test/thumb.png?X-Amz-Signature=1']]]]),
            $this->base.'/api/v1/messages*' => Http::response(['data' => []]),
            'https://storage.example.test/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);
        $updated = (string) $this->mine->updated_at;
        $this->travel(5)->minutes();
        $this->actingAs($this->ana)->getJson(route('leads.chat.messages', $this->mine))->assertOk()
            ->assertJsonPath('avatar', fn ($url) => str_starts_with($url, route('leads.chat.photo', $this->mine)));
        $lead = $this->mine->fresh();
        $this->assertSame('lead-photos/'.$lead->id.'.png', $lead->photo_path);
        $this->assertSame($updated, (string) $lead->updated_at, 'A foto não muda a ordem das conversas.');
        $this->get(route('leads.chat.photo', $lead))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(route('leads.chat.index'))->assertSee(e($lead->photoUrl()), false);
        $this->actingAs($this->bia)->get(route('leads.chat.photo', $lead))->assertForbidden();
        // Abrir de novo no mesmo dia não baixa a foto outra vez.
        $before = count(Http::recorded());
        $this->actingAs($this->ana)->getJson(route('leads.chat.messages', $this->mine))->assertOk();
        $this->assertCount($before + 2, Http::recorded());
        $lead->delete();
        Storage::disk('local')->assertMissing('lead-photos/'.$lead->id.'.png');
    }

    public function test_scheduled_sync_saves_photos_without_opening_the_chat(): void
    {
        Storage::fake('local');
        $this->connection($this->ana);
        $this->connection($this->admin, 'token-do-admin');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        Http::fake([
            $this->base.'/api/v1/contacts*' => Http::response(['data' => [$this->contact() + ['thumbAvatar' => ['url' => 'https://storage.example.test/thumb.png']]]]),
            'https://storage.example.test/*' => Http::response($png),
        ]);
        $this->artisan('leads:digisac-photos')->assertSuccessful();
        foreach ([$this->mine, $this->theirs, $this->queue] as $lead) $this->assertNotNull($lead->fresh()->photo_path, $lead->name);
        // O lead da Ana usa o token dela; o da Bia (sem conexão) e o da fila usam o do administrador.
        $tokens = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Authorization')[0] ?? null)->filter()->countBy()->all();
        ksort($tokens);
        $this->assertSame(['Bearer token-do-admin' => 2, 'Bearer token-secreto-ana' => 1], $tokens);
        // Conferidos há menos de 7 dias: a próxima rodada não consulta de novo.
        $before = count(Http::recorded());
        $this->artisan('leads:digisac-photos')->assertSuccessful();
        $this->assertCount($before, Http::recorded());
    }

    public function test_photo_and_recorded_audio_are_sent_with_the_type_read_from_the_content(): void
    {
        $this->connection($this->ana);
        Http::fake([
            $this->base.'/api/v1/contacts*' => Http::response(['data' => [$this->contact()]]),
            $this->base.'/api/v1/messages' => Http::response(['id' => 'sent'], 201),
        ]);
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
        // O navegador diz "foto.pdf", mas o conteúdo é PNG: vale o conteúdo.
        $this->actingAs($this->ana)->postJson(route('leads.chat.send', $this->mine), $this->payload(['text' => 'Olha a carteirinha', 'file' => ['data' => $png, 'name' => 'foto<script>.pdf']]))->assertOk();
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST' && $request['file'] === ['base64' => $png, 'mimetype' => 'image/png', 'name' => 'fotoscript.pdf']
            && $request['text'] === 'Olha a carteirinha' && ! isset($request['type']) && $request['contactId'] === 'contact-1');
        // Áudio gravado no Chrome: MP4 só de som, sem texto.
        $this->postJson(route('leads.chat.send', $this->mine), $this->payload(['text' => '', 'file' => ['data' => 'AAAAHGZ0eXBNNEEgAAAAAE00QSBtcDQyaXNvbQ==', 'name' => 'audio.m4a']]))->assertOk();
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST' && ($request['file']['mimetype'] ?? null) === 'audio/mp4' && $request['text'] === '');
    }

    public function test_files_of_other_types_or_too_large_never_reach_digisac(): void
    {
        $this->connection($this->ana);
        Http::fake();
        $this->actingAs($this->ana)->postJson(route('leads.chat.send', $this->mine), $this->payload(['file' => ['data' => base64_encode('<?php echo 1;'), 'name' => 'foto.jpg']]))
            ->assertUnprocessable()->assertJsonPath('message', 'Envie foto, áudio, vídeo MP4 ou PDF.');
        $this->postJson(route('leads.chat.send', $this->mine), $this->payload(['file' => ['data' => base64_encode("\x89PNG\r\n\x1a\n".str_repeat('a', 5 * 1024 * 1024)), 'name' => 'grande.png']]))
            ->assertUnprocessable();
        $this->postJson(route('leads.chat.send', $this->mine), $this->payload(['file' => ['data' => 'não é base64!', 'name' => 'x.png']]))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_missing_configuration_and_blank_messages_do_not_call_api(): void
    {
        $this->actingAs($this->ana)->get(route('leads.chat.show', $this->mine))->assertOk()->assertSee('Conecte seu Digisac');
        $this->getJson(route('leads.chat.messages', $this->mine))->assertUnprocessable();
        $this->postJson(route('leads.chat.send', $this->mine), $this->payload(['text' => '   ']))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_token_is_encrypted_hidden_and_validated_without_sending_a_message(): void
    {
        Http::fake([
            // Formato real da API: listagem paginada em "data", sem campo "active".
            $this->base.'/api/v1/users*' => Http::response(['data' => [['id' => 'remote-ana', 'name' => 'Ana Digisac', 'email' => $this->ana->email, 'archivedAt' => null, 'deletedAt' => null, 'accountId' => 'account-1']], 'total' => 1]),
            $this->base.'/api/v1/services*' => Http::response(['data' => [['id' => 'service-1', 'name' => 'São Francisco', 'type' => 'whatsapp', 'archivedAt' => null, 'accountId' => 'account-1']], 'total' => 1]),
        ]);
        $payload = ['base_url' => $this->base, 'digisac_user_id' => 'remote-ana', 'service_id' => 'service-1', 'api_token' => 'muito-secreto'];
        $this->actingAs($this->ana)->post(route('leads.chat.connection.save'), $payload)->assertRedirect();
        $connection = DigisacConnection::where('user_id', $this->ana->id)->sole();
        $this->assertSame('muito-secreto', $connection->api_token);
        $this->assertStringNotContainsString('muito-secreto', DB::table('digisac_connections')->value('api_token'));
        $this->assertArrayNotHasKey('api_token', $connection->toArray());
        $this->get(route('leads.chat.connection'))->assertOk()->assertSee('Conexão validada')->assertDontSee('muito-secreto');
        // Token vazio mantém o token cifrado existente sem corromper a credencial.
        $this->post(route('leads.chat.connection.save'), array_replace($payload, ['api_token' => '']))->assertRedirect();
        $this->assertSame('muito-secreto', $connection->fresh()->api_token);
        Http::assertNotSent(fn (HttpRequest $request) => $request->method() === 'POST');
        Http::assertSent(fn (HttpRequest $request) => str_contains(urldecode($request->url()), '/api/v1/users?where[id]=remote-ana'));
        Http::assertSent(fn (HttpRequest $request) => str_contains(urldecode($request->url()), '/api/v1/services?where[id]=service-1'));
    }

    public function test_archived_digisac_user_or_unknown_ids_are_refused(): void
    {
        $payload = ['base_url' => $this->base, 'digisac_user_id' => 'remote-ana', 'service_id' => 'service-1', 'api_token' => 'segredo'];
        $service = Http::response(['data' => [['id' => 'service-1', 'archivedAt' => null, 'accountId' => 'account-1']]]);
        Http::fakeSequence($this->base.'/api/v1/users*')
            ->push(['data' => [['id' => 'remote-ana', 'email' => $this->ana->email, 'archivedAt' => '2026-10-01T10:00:00Z', 'accountId' => 'account-1']]])
            ->push(['data' => []]);
        Http::fake([$this->base.'/api/v1/services*' => $service]);
        $this->actingAs($this->ana)->post(route('leads.chat.connection.save'), $payload)->assertSessionHasErrors('digisac_user_id');
        $this->post(route('leads.chat.connection.save'), $payload)->assertSessionHasErrors('digisac_user_id');
        $this->assertDatabaseCount('digisac_connections', 0);
    }

    public function test_invalid_settings_never_flash_tokens_or_accept_another_users_connection(): void
    {
        $this->connection($this->bia, 'segredo-da-bia');
        $this->actingAs($this->ana)->get(route('leads.chat.connection', ['user_id' => $this->bia->id]))->assertForbidden();
        $this->post(route('leads.chat.connection.save'), ['user_id' => $this->bia->id])->assertForbidden();
        $this->post(route('leads.chat.connection.destroy'), ['user_id' => $this->bia->id])->assertForbidden();
        foreach (['http://saofranciscopet.digisac.chat', 'https://127.0.0.1', 'https://saofranciscopet.digisac.chat.evil.test', $this->base.'?redirect=evil'] as $url) {
            $this->post(route('leads.chat.connection.save'), ['base_url' => $url, 'api_token' => 'nao-vazar', 'digisac_user_id' => 'id', 'service_id' => 'id'])
                ->assertSessionHasErrors('base_url')->assertSessionMissing('_old_input.api_token');
        }
        $this->assertDatabaseHas('digisac_connections', ['user_id' => $this->bia->id]);
        Http::assertNothingSent();
    }

    public function test_remote_identity_must_match_email_unless_mapping_is_made_by_admin(): void
    {
        Http::fake([
            $this->base.'/api/v1/users*' => Http::response(['data' => [['id' => 'remote-bia', 'email' => $this->bia->email, 'archivedAt' => null, 'accountId' => 'a1']]]),
            $this->base.'/api/v1/services*' => Http::response(['data' => [['id' => 'service-1', 'archivedAt' => null, 'accountId' => 'a1']]]),
        ]);
        $payload = ['base_url' => $this->base, 'api_token' => 'segredo', 'digisac_user_id' => 'remote-bia', 'service_id' => 'service-1'];
        $this->actingAs($this->ana)->post(route('leads.chat.connection.save'), $payload)->assertSessionHasErrors('digisac_user_id')->assertSessionMissing('_old_input.api_token');
        $this->assertDatabaseCount('digisac_connections', 0);
        $this->actingAs($this->admin)->post(route('leads.chat.connection.save'), $payload + ['user_id' => $this->ana->id])->assertRedirect();
        $this->assertDatabaseHas('digisac_connections', ['user_id' => $this->ana->id, 'digisac_user_id' => 'remote-bia']);
    }

    public function test_uncertain_send_is_not_retried_and_errors_do_not_expose_tokens(): void
    {
        $this->connection($this->ana);
        Http::fake([
            $this->base.'/api/v1/contacts*' => Http::response(['data' => [$this->contact()]]),
            $this->base.'/api/v1/messages' => Http::failedConnection(),
        ]);
        $payload = $this->payload();
        $this->actingAs($this->ana)->postJson(route('leads.chat.send', $this->mine), $payload)->assertStatus(503)->assertDontSee('token-secreto');
        $this->postJson(route('leads.chat.send', $this->mine), $payload)->assertConflict();
        $this->assertSame('new', $this->mine->fresh()->status);
        $posts = Http::recorded(fn (HttpRequest $request) => $request->method() === 'POST');
        $this->assertCount(1, $posts);
    }

    public function test_existing_job_permissions_control_read_and_send_separately(): void
    {
        $role = JobRole::create(['name' => 'Consulta de leads', 'base_role' => 'attendant', 'permissions' => ['leads.view']]);
        $this->ana->update(['job_role_id' => $role->id]);
        $this->actingAs($this->ana)->get(route('leads.chat.index'))->assertOk();
        $this->postJson(route('leads.chat.send', $this->mine), $this->payload())->assertForbidden();
        $this->post(route('leads.chat.claim', $this->queue))->assertForbidden();
        Http::assertNothingSent();
    }
}

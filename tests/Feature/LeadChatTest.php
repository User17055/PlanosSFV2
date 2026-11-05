<?php

namespace Tests\Feature;

use App\Models\{DigisacConnection, Franchise, JobRole, Lead, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\{Cache, DB, Http};
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

    public function test_chat_lists_only_owned_leads_and_a_separate_queue_even_for_admin(): void
    {
        $this->actingAs($this->ana)->get(route('leads.chat.index'))->assertOk()->assertSee('Cliente da Ana')->assertDontSee('Cliente da Bia')->assertDontSee('Cliente disponível');
        $this->get(route('leads.chat.index', ['fila' => 1]))->assertOk()->assertSee('Cliente disponível')->assertDontSee('Cliente da Ana')->assertDontSee('Cliente da Bia');
        $this->get(route('leads.chat.index', ['q' => 'Bia']))->assertOk()->assertDontSee('Cliente da Bia');
        $this->actingAs($this->admin)->get(route('leads.chat.index'))->assertOk()->assertDontSee('Cliente da Ana')->assertDontSee('Cliente da Bia');
        $this->get(route('leads.chat.show', $this->theirs))->assertForbidden();
        Http::assertNothingSent();
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
            $this->base.'/api/v1/users/remote-ana' => Http::response(['id' => 'remote-ana', 'name' => 'Ana Digisac', 'email' => $this->ana->email, 'active' => true, 'accountId' => 'account-1']),
            $this->base.'/api/v1/services/service-1' => Http::response(['id' => 'service-1', 'accountId' => 'account-1']),
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
            $this->base.'/api/v1/users/remote-bia' => Http::response(['id' => 'remote-bia', 'email' => $this->bia->email, 'active' => true, 'accountId' => 'a1']),
            $this->base.'/api/v1/services/service-1' => Http::response(['id' => 'service-1', 'accountId' => 'a1']),
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

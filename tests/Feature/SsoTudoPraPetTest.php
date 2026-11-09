<?php

namespace Tests\Feature;

use App\Models\{Customer, Franchise, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Entrar com São Francisco" na Tudo pra Pet: OAuth 2.0 com código de autorização e PKCE. */
class SsoTudoPraPetTest extends TestCase
{
    use RefreshDatabase;

    private const BACK = 'https://tudoprapet.example.test/auth/sao-francisco';
    private User $tutor;
    private string $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.sso_tudoprapet' => ['client_id' => 'tudoprapet', 'client_secret' => 'segredo-de-teste', 'redirect_uris' => [self::BACK]]]);
        $unit = Franchise::create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $customer = Customer::create(['name' => 'Maria Tutora', 'document' => '529.982.247-25', 'email' => 'maria@example.test', 'phone' => '(18) 99999-0000', 'originating_franchise_id' => $unit->id]);
        $this->tutor = User::factory()->create(['role' => 'customer', 'active' => true, 'customer_id' => $customer->id, 'email' => 'maria@example.test', 'email_verified_at' => now()]);
        $this->verifier = str_repeat('v', 50);
    }

    private function params(array $extra = []): array
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
        return $extra + ['response_type' => 'code', 'client_id' => 'tudoprapet', 'redirect_uri' => self::BACK, 'state' => 'estado-123', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'];
    }

    private function codeFrom(string $location): string
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $this->assertSame('estado-123', $q['state']);
        return $q['code'];
    }

    private function exchange(string $code, array $extra = [])
    {
        return $this->postJson(route('oauth.token'), $extra + ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::BACK,
            'code_verifier' => $this->verifier, 'client_id' => 'tudoprapet', 'client_secret' => 'segredo-de-teste']);
    }

    public function test_tutor_authorizes_once_and_the_store_gets_only_the_agreed_data(): void
    {
        $this->get(route('oauth.authorize', $this->params()))->assertRedirect(route('login'));
        $this->actingAs($this->tutor)->get(route('oauth.authorize', $this->params()))->assertOk()->assertSee('Permitir e continuar')->assertSee('Sua senha não é compartilhada');
        $location = $this->post(route('oauth.approve'), $this->params(['decision' => 'allow']))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::BACK.'?', $location);
        $token = $this->exchange($this->codeFrom($location))->assertOk()->assertJsonPath('token_type', 'Bearer')->json('access_token');
        $this->getJson(route('oauth.userinfo'), ['Authorization' => 'Bearer '.$token])->assertOk()
            ->assertExactJson(['sub' => 'sf-'.$this->tutor->customer_id, 'name' => 'Maria Tutora', 'email' => 'maria@example.test', 'email_verified' => true,
                'cpf' => '52998224725', 'phone' => '18999990000', 'plano_ativo' => false]);
        // Na segunda vez não pergunta de novo.
        $this->assertStringStartsWith(self::BACK.'?', $this->get(route('oauth.authorize', $this->params()))->assertRedirect()->headers->get('Location'));
    }

    public function test_reused_code_wrong_verifier_wrong_secret_and_foreign_return_address_are_refused(): void
    {
        $this->actingAs($this->tutor)->post(route('oauth.approve'), $this->params(['decision' => 'allow']));
        $code = $this->codeFrom($this->get(route('oauth.authorize', $this->params()))->headers->get('Location'));
        $this->exchange($code, ['client_secret' => 'errado'])->assertStatus(401)->assertJsonPath('error', 'invalid_client');
        $this->exchange($code, ['code_verifier' => str_repeat('x', 50)])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        // O código saiu do cache na tentativa anterior: não serve de novo, nem com o verificador certo.
        $this->exchange($code)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->get(route('oauth.authorize', $this->params(['redirect_uri' => 'https://ataque.example.test/'])))->assertStatus(400);
        $this->getJson(route('oauth.userinfo'), ['Authorization' => 'Bearer inventado'])->assertStatus(401);
    }

    public function test_cancel_returns_access_denied_and_staff_cannot_use_it(): void
    {
        $location = $this->actingAs($this->tutor)->post(route('oauth.approve'), $this->params(['decision' => 'deny']))->headers->get('Location');
        $this->assertStringContainsString('error=access_denied', $location);
        $admin = User::factory()->create(['role' => 'central_admin', 'active' => true]);
        $this->actingAs($admin)->get(route('oauth.authorize', $this->params()))->assertForbidden();
    }
}

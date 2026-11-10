<?php

namespace Tests\Feature;

use App\Models\{Customer, Franchise, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Entrar com São Francisco" na Tudo pra Pet: OpenID Connect com código de autorização (PKCE opcional). */
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
        return $extra + ['response_type' => 'code', 'client_id' => 'tudoprapet', 'redirect_uri' => self::BACK, 'state' => 'estado-123', 'scope' => 'openid email profile phone',
            'nonce' => 'nonce-abc', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'];
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
        $answer = $this->exchange($this->codeFrom($location))->assertOk()->assertJsonPath('token_type', 'Bearer');
        $claims = ['sub' => 'sf-'.$this->tutor->customer_id, 'name' => 'Maria Tutora', 'email' => 'maria@example.test', 'email_verified' => true,
            'phone_number' => '+5518999990000', 'cpf' => '52998224725', 'plano_ativo' => false];
        $this->getJson(route('oauth.userinfo'), ['Authorization' => 'Bearer '.$answer->json('access_token')])->assertOk()->assertExactJson($claims);

        // id_token assinado com a chave publicada no jwks, para esta loja e com o nonce pedido.
        [$head, $body, $signature] = explode('.', $answer->json('id_token'));
        $decode = fn ($part) => base64_decode(strtr($part, '-_', '+/'));
        $key = $this->getJson(route('oauth.jwks'))->assertOk()->json('keys.0');
        $this->assertSame(json_decode($decode($head), true)['kid'], $key['kid']);
        $public = openssl_pkey_get_details(openssl_pkey_get_private(file_get_contents(storage_path('app/private/oidc-tudoprapet.pem'))));
        $this->assertSame(rtrim(strtr(base64_encode($public['rsa']['n']), '+/', '-_'), '='), $key['n']);
        $this->assertSame(1, openssl_verify($head.'.'.$body, $decode($signature), $public['key'], OPENSSL_ALGO_SHA256));
        $token = json_decode($decode($body), true);
        $this->assertSame([rtrim(config('app.url'), '/'), 'tudoprapet', 'nonce-abc'], [$token['iss'], $token['aud'], $token['nonce']]);
        $this->assertGreaterThan(time(), $token['exp']);
        $this->assertSame($claims, array_intersect_key($token, $claims));
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
        // Administrador já logado vai para a escolha de área; os outros perfis da equipe não usam.
        $admin = User::factory()->create(['role' => 'central_admin', 'active' => true]);
        $this->actingAs($admin)->get(route('oauth.authorize', $this->params()))->assertRedirect(route('account.choose'));
        $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
        $this->actingAs($manager)->get(route('oauth.authorize', $this->params()))->assertForbidden();
    }

    public function test_admin_who_is_also_the_tutor_enters_the_store_without_typing_the_password_again(): void
    {
        $this->tutor->update(['name' => 'Maria Tutora da Silva', 'password' => 'Senha-Comum-2026']);
        User::factory()->create(['role' => 'central_admin', 'active' => true, 'name' => 'MARIA TUTORA', 'email' => 'admin@example.test', 'password' => 'Senha-Comum-2026']);
        $store = route('oauth.authorize', $this->params());
        $this->get($store)->assertRedirect(route('login'));
        $this->post(route('authenticate'), ['email' => 'admin@example.test', 'password' => 'Senha-Comum-2026'])->assertRedirect(route('account.choose'));
        $this->get(route('account.choose'))->assertOk()->assertSee('Para entrar na Tudo pra Pet')->assertDontSee('Senha da conta de cliente');
        $back = $this->post(route('account.customer'))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(route('oauth.authorize').'?', $back);
        $this->assertAuthenticatedAs($this->tutor);
        $this->get($back)->assertOk()->assertSee('Permitir e continuar');
    }

    public function test_discovery_points_to_the_endpoints_and_basic_auth_without_pkce_works(): void
    {
        $base = rtrim(config('app.url'), '/');
        $this->getJson('/.well-known/openid-configuration')->assertOk()->assertJsonPath('issuer', $base)
            ->assertJsonPath('authorization_endpoint', $base.'/oauth/autorizar')->assertJsonPath('token_endpoint', $base.'/oauth/token')
            ->assertJsonPath('userinfo_endpoint', $base.'/oauth/usuario')->assertJsonPath('jwks_uri', $base.'/oauth/jwks')
            ->assertJsonPath('id_token_signing_alg_values_supported', ['RS256']);
        $this->actingAs($this->tutor)->post(route('oauth.approve'), $this->params(['decision' => 'allow']));
        $plain = array_diff_key($this->params(), ['code_challenge' => 1, 'code_challenge_method' => 1, 'nonce' => 1]);
        $code = $this->codeFrom($this->get(route('oauth.authorize', $plain))->headers->get('Location'));
        $this->post(route('oauth.token'), ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::BACK],
            ['Authorization' => 'Basic '.base64_encode('tudoprapet:segredo-de-teste')])->assertOk()->assertJsonStructure(['access_token', 'id_token']);
    }
}

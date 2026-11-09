<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog, User};
use App\Services\Domain\Audit;
use App\Services\Integrations\TudoPraPetMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Entrar com São Francisco" na loja da Tudo pra Pet: OpenID Connect (código de autorização, PKCE S256 opcional),
 * um único cliente configurado. O tutor entra aqui e a loja recebe só nome, e-mail, CPF, telefone e se há plano ativo.
 */
class OAuthController extends Controller
{
    private const CONSENT = 'sso.tudoprapet.consent';
    private const TTL = 300;

    public function discovery()
    {
        $base = $this->issuer();
        return response()->json([
            'issuer' => $base,
            'authorization_endpoint' => $base.route('oauth.authorize', [], false),
            'token_endpoint' => $base.route('oauth.token', [], false),
            'userinfo_endpoint' => $base.route('oauth.userinfo', [], false),
            'jwks_uri' => $base.route('oauth.jwks', [], false),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'profile', 'email', 'phone'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => ['sub', 'name', 'email', 'email_verified', 'phone_number', 'cpf', 'plano_ativo'],
        ]);
    }

    public function jwks()
    {
        $rsa = openssl_pkey_get_details($this->key())['rsa'];
        return response()->json(['keys' => [['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $this->kid($rsa['n']),
            'n' => $this->b64($rsa['n']), 'e' => $this->b64($rsa['e'])]]])->header('Cache-Control', 'public, max-age=3600');
    }

    public function authorize(Request $request)
    {
        $data = $this->request($request);
        $user = $request->user();
        if (AuditLog::where('action', self::CONSENT)->where('actor_id', $user->id)->exists()) {
            return $this->issue($user, $data);
        }
        return view('auth.oauth-consent', ['data' => array_filter($data, fn ($v) => $v !== null)]);
    }

    public function approve(Request $request)
    {
        $data = $this->request($request);
        $user = $request->user();
        if ($request->input('decision') !== 'allow') {
            return redirect()->away($this->back($data['redirect_uri'], ['error' => 'access_denied', 'state' => $data['state']]));
        }
        Audit::record(self::CONSENT, $user->customer, $user, ['client' => $data['client_id']]);
        return $this->issue($user, $data);
    }

    public function token(Request $request, TudoPraPetMembership $membership)
    {
        $client = config('integrations.sso_tudoprapet');
        $id = (string) ($request->input('client_id') ?? $request->getUser());
        $secret = (string) ($request->input('client_secret') ?? $request->getPassword());
        if (! $client['client_secret'] || ! hash_equals((string) $client['client_id'], $id) || ! hash_equals((string) $client['client_secret'], $secret)) {
            return response()->json(['error' => 'invalid_client'], 401);
        }
        if ($request->input('grant_type') !== 'authorization_code' || ! is_string($request->input('code'))) {
            return response()->json(['error' => 'invalid_request'], 400);
        }
        // Código de uso único: sai do cache na primeira leitura, certo ou errado.
        $entry = Cache::pull('sso:code:'.hash('sha256', $request->input('code')));
        if (! $entry || $entry['redirect_uri'] !== $request->input('redirect_uri') || ! $this->pkceOk($entry['challenge'], $request->input('code_verifier'))) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }
        $user = User::find($entry['user_id']);
        if (! $user || ! $user->active || ! $user->customer) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }
        $token = Str::random(64);
        Cache::put('sso:token:'.hash('sha256', $token), $user->id, now()->addSeconds(self::TTL));
        return response()->json(['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => self::TTL, 'scope' => $entry['scope'],
            'id_token' => $this->idToken($user, $entry, $membership)])->header('Cache-Control', 'no-store');
    }

    public function userinfo(Request $request, TudoPraPetMembership $membership)
    {
        $userId = $request->bearerToken() ? Cache::get('sso:token:'.hash('sha256', $request->bearerToken())) : null;
        $user = $userId ? User::find($userId) : null;
        if (! $user || ! $user->active || $user->role !== 'customer' || ! $user->customer) {
            return response()->json(['error' => 'invalid_token'], 401);
        }
        return response()->json($this->claims($user, $membership))->header('Cache-Control', 'no-store');
    }

    /** Dados do tutor; `plano_ativo` é só informativo: quem tem o preço do grupo continua decidido pela API do grupo. */
    private function claims(User $user, TudoPraPetMembership $membership): array
    {
        $customer = $user->customer;
        $phone = preg_replace('/\D/', '', (string) $customer->phone);
        return ['sub' => 'sf-'.$customer->id, 'name' => $customer->name, 'email' => $user->email, 'email_verified' => (bool) $user->email_verified_at,
            'phone_number' => $phone !== '' ? '+55'.$phone : null, 'cpf' => preg_replace('/\D/', '', (string) $customer->document),
            'plano_ativo' => $membership->desired($customer)];
    }

    private function idToken(User $user, array $entry, TudoPraPetMembership $membership): string
    {
        $key = $this->key();
        $head = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->kid(openssl_pkey_get_details($key)['rsa']['n'])];
        $body = array_filter(['iss' => $this->issuer(), 'aud' => config('integrations.sso_tudoprapet.client_id'), 'iat' => time(),
            'exp' => time() + self::TTL, 'nonce' => $entry['nonce']], fn ($v) => $v !== null) + $this->claims($user, $membership);
        $signed = $this->b64(json_encode($head)).'.'.$this->b64(json_encode($body));
        openssl_sign($signed, $signature, $key, OPENSSL_ALGO_SHA256);
        return $signed.'.'.$this->b64($signature);
    }

    /** Chave RSA do servidor, criada na primeira vez; fica fora do Git e nunca sai daqui (só a parte pública, no jwks). */
    private function key(): \OpenSSLAsymmetricKey
    {
        $path = storage_path('app/private/oidc-tudoprapet.pem');
        if (! is_file($path)) {
            openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
            $tmp = $path.'.'.Str::random(8);
            file_put_contents($tmp, $pem);
            chmod($tmp, 0600);
            @link($tmp, $path); // se outra requisição criou antes, fica a dela
            @unlink($tmp);
        }
        return openssl_pkey_get_private(file_get_contents($path));
    }

    private function kid(string $modulus): string
    {
        return substr($this->b64(hash('sha256', $modulus, true)), 0, 16);
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function issuer(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** PKCE é opcional (o cliente já se identifica com o segredo); se veio na autorização, o verificador tem de bater. */
    private function pkceOk(?string $challenge, mixed $verifier): bool
    {
        return $challenge === null || (is_string($verifier) && hash_equals($challenge, $this->b64(hash('sha256', $verifier, true))));
    }

    /** Parâmetros da autorização; endereço de volta fora da lista nunca recebe redirecionamento. */
    private function request(Request $request): array
    {
        $client = config('integrations.sso_tudoprapet');
        $data = $request->validate(['response_type' => 'required|in:code', 'client_id' => 'required|string|max:100', 'redirect_uri' => 'required|string|max:500',
            'state' => 'nullable|string|max:300', 'scope' => 'nullable|string|max:200', 'nonce' => 'nullable|string|max:300',
            'code_challenge' => ['nullable', 'required_with:code_challenge_method', 'string', 'regex:/^[A-Za-z0-9_-]{43,128}$/'],
            'code_challenge_method' => 'nullable|required_with:code_challenge|in:S256']);
        abort_unless($client['client_secret'] && hash_equals((string) $client['client_id'], $data['client_id'])
            && in_array($data['redirect_uri'], $client['redirect_uris'], true), 400, 'Aplicativo ou endereço de volta não autorizado.');
        abort_unless($request->user()->customer, 403, 'Entre com a sua conta de cliente.');
        return $data + ['state' => null, 'scope' => null, 'nonce' => null, 'code_challenge' => null];
    }

    private function issue(User $user, array $data)
    {
        $code = Str::random(48);
        Cache::put('sso:code:'.hash('sha256', $code), ['user_id' => $user->id, 'redirect_uri' => $data['redirect_uri'], 'challenge' => $data['code_challenge'],
            'nonce' => $data['nonce'], 'scope' => $data['scope'] ?? 'openid'], now()->addMinutes(2));
        Audit::record('sso.tudoprapet.login', $user->customer, $user);
        return redirect()->away($this->back($data['redirect_uri'], array_filter(['code' => $code, 'state' => $data['state']], fn ($v) => $v !== null)));
    }

    private function back(string $uri, array $query): string
    {
        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($query);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog, User};
use App\Services\Domain\Audit;
use App\Services\Integrations\TudoPraPetMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Entrar com São Francisco" na loja da Tudo pra Pet: OAuth 2.0 com código de autorização e PKCE (S256), um único
 * cliente configurado. O tutor entra aqui e a loja recebe só nome, e-mail, CPF, telefone e se há plano ativo.
 */
class OAuthController extends Controller
{
    private const CONSENT = 'sso.tudoprapet.consent';

    public function authorize(Request $request)
    {
        $data = $this->request($request);
        $user = $request->user();
        if (AuditLog::where('action', self::CONSENT)->where('actor_id', $user->id)->exists()) {
            return $this->issue($user, $data);
        }
        return view('auth.oauth-consent', ['data' => $data]);
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

    public function token(Request $request)
    {
        $client = config('integrations.sso_tudoprapet');
        $id = (string) ($request->input('client_id') ?? $request->getUser());
        $secret = (string) ($request->input('client_secret') ?? $request->getPassword());
        if (! $client['client_secret'] || ! hash_equals((string) $client['client_id'], $id) || ! hash_equals((string) $client['client_secret'], $secret)) {
            return response()->json(['error' => 'invalid_client'], 401);
        }
        if ($request->input('grant_type') !== 'authorization_code' || ! is_string($request->input('code')) || ! is_string($request->input('code_verifier'))) {
            return response()->json(['error' => 'invalid_request'], 400);
        }
        // Código de uso único: sai do cache na primeira leitura, certo ou errado.
        $entry = Cache::pull('sso:code:'.hash('sha256', $request->input('code')));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', (string) $request->input('code_verifier'), true)), '+/', '-_'), '=');
        if (! $entry || $entry['redirect_uri'] !== $request->input('redirect_uri') || ! hash_equals($entry['challenge'], $challenge)) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }
        $token = Str::random(64);
        Cache::put('sso:token:'.hash('sha256', $token), $entry['user_id'], now()->addMinutes(5));
        return response()->json(['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 300])->header('Cache-Control', 'no-store');
    }

    public function userinfo(Request $request, TudoPraPetMembership $membership)
    {
        $userId = $request->bearerToken() ? Cache::get('sso:token:'.hash('sha256', $request->bearerToken())) : null;
        $user = $userId ? User::find($userId) : null;
        if (! $user || ! $user->active || $user->role !== 'customer' || ! $user->customer) {
            return response()->json(['error' => 'invalid_token'], 401);
        }
        $customer = $user->customer;
        return response()->json(['sub' => 'sf-'.$customer->id, 'name' => $customer->name, 'email' => $user->email, 'email_verified' => (bool) $user->email_verified_at,
            'cpf' => preg_replace('/\D/', '', (string) $customer->document), 'phone' => preg_replace('/\D/', '', (string) $customer->phone),
            'plano_ativo' => $membership->desired($customer)])->header('Cache-Control', 'no-store');
    }

    /** Parâmetros da autorização; endereço de volta fora da lista nunca recebe redirecionamento. */
    private function request(Request $request): array
    {
        $client = config('integrations.sso_tudoprapet');
        $data = $request->validate(['response_type' => 'required|in:code', 'client_id' => 'required|string|max:100', 'redirect_uri' => 'required|string|max:500',
            'state' => 'required|string|max:300', 'code_challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43,128}$/'], 'code_challenge_method' => 'required|in:S256']);
        abort_unless($client['client_secret'] && hash_equals((string) $client['client_id'], $data['client_id'])
            && in_array($data['redirect_uri'], $client['redirect_uris'], true), 400, 'Aplicativo ou endereço de volta não autorizado.');
        abort_unless($request->user()->customer, 403, 'Entre com a sua conta de cliente.');
        return $data;
    }

    private function issue(User $user, array $data)
    {
        $code = Str::random(48);
        Cache::put('sso:code:'.hash('sha256', $code), ['user_id' => $user->id, 'redirect_uri' => $data['redirect_uri'], 'challenge' => $data['code_challenge']], now()->addMinutes(2));
        Audit::record('sso.tudoprapet.login', $user->customer, $user);
        return redirect()->away($this->back($data['redirect_uri'], ['code' => $code, 'state' => $data['state']]));
    }

    private function back(string $uri, array $query): string
    {
        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($query);
    }
}

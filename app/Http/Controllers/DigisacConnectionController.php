<?php

namespace App\Http\Controllers;

use App\Models\{DigisacConnection, User};
use App\Services\Integrations\DigisacClient;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DigisacConnectionController extends Controller
{
    private function target(Request $request): User
    {
        $id = $request->input('user_id', $request->user()->id);
        abort_unless((string) $id === (string) $request->user()->id || $request->user()->role === 'central_admin', 403);
        $user = User::findOrFail($id);
        abort_unless($user->active && in_array($user->role, User::AREAS['leads'][2], true) && $user->canOpen('leads'), 403);
        return $user;
    }

    public function edit(Request $request)
    {
        $target = $this->target($request);
        return response()->view('leads.chat-connection', [
            'target' => $target,
            'connection' => DigisacConnection::where('user_id', $target->id)->first(),
            'users' => $request->user()->role === 'central_admin'
                ? User::where('active', true)->whereIn('role', User::AREAS['leads'][2])->orderBy('name')->get(['id', 'name']) : collect(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, DigisacClient $client)
    {
        $target = $this->target($request);
        $existing = DigisacConnection::where('user_id', $target->id)->first();
        $data = $request->validate([
            'base_url' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (! DigisacClient::baseUrl($value)) $fail('Use o endereço HTTPS do Digisac. Domínios próprios precisam ser autorizados no servidor.');
            }],
            'api_token' => [$existing ? 'nullable' : 'required', 'string', 'max:4096', 'not_regex:/[\r\n]/'],
            'digisac_user_id' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'service_id' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
        ]);
        $base = DigisacClient::baseUrl($data['base_url']);
        if ($existing && $base !== $existing->base_url && empty($data['api_token'])) {
            throw ValidationException::withMessages(['api_token' => 'Informe um novo token ao trocar o endereço do Digisac.']);
        }
        $connection = new DigisacConnection([
            'user_id' => $target->id, 'base_url' => $base,
            'api_token' => ($data['api_token'] ?? null) ?: $existing?->api_token,
            'digisac_user_id' => $data['digisac_user_id'], 'service_id' => $data['service_id'],
        ]);
        $remote = $client->record($connection, 'users/'.rawurlencode($connection->digisac_user_id));
        $service = $client->record($connection, 'services/'.rawurlencode($connection->service_id));
        if ((string) ($remote['id'] ?? '') !== $connection->digisac_user_id || empty($remote['active']) || ! empty($remote['archivedAt'])
            || (string) ($service['id'] ?? '') !== $connection->service_id || ! empty($service['archivedAt'])
            || empty($remote['accountId']) || ($remote['accountId'] !== ($service['accountId'] ?? null))) {
            throw ValidationException::withMessages(['digisac_user_id' => 'Confira o usuário ativo e a conexão: ambos precisam pertencer à mesma conta do Digisac.']);
        }
        if ($request->user()->role !== 'central_admin' && strcasecmp($remote['email'] ?? '', $target->email) !== 0) {
            throw ValidationException::withMessages(['digisac_user_id' => 'O e-mail do usuário Digisac precisa ser o mesmo do seu acesso. Para e-mails diferentes, peça o vínculo à administração central.']);
        }
        if ($existing) {
            $existing->fill([
                'base_url' => $connection->base_url, 'api_token' => $connection->api_token,
                'digisac_user_id' => $connection->digisac_user_id, 'service_id' => $connection->service_id,
            ]);
            $connection = $existing;
        }
        $connection->remote_name = mb_substr((string) ($remote['name'] ?? ''), 0, 255);
        $connection->verified_at = now();
        $connection->save();
        return redirect()->route('leads.chat.connection', ['user_id' => $target->id])->with('success', 'Conexão com o Digisac validada e salva.');
    }

    public function destroy(Request $request)
    {
        $target = $this->target($request);
        DigisacConnection::where('user_id', $target->id)->delete();
        return redirect()->route('leads.chat.connection', ['user_id' => $target->id])->with('success', 'Conexão removida deste sistema.');
    }
}

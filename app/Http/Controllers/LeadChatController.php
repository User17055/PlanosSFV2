<?php

namespace App\Http\Controllers;

use App\Models\{DigisacConnection, Lead};
use App\Services\AccessService;
use App\Services\Integrations\DigisacClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LeadChatController extends Controller
{
    private function available(Request $request, AccessService $access)
    {
        return $access->scope(Lead::visibleTo($request->user()), $request->user());
    }

    private function owned(Request $request, Lead $lead, AccessService $access): void
    {
        $access->franchise($request->user(), (int) $lead->originating_franchise_id);
        abort_unless(Lead::whereKey($lead->id)->where('handled_by', $request->user()->id)->exists(), 403, 'Assuma o lead disponível para abrir a conversa. Leads de outras pessoas não podem ser acessados.');
    }

    /** Ler é dos administradores e do atendente do lead; responder fica só com o atendente (owned). Lead da fila precisa ser assumido antes. */
    private function readable(Request $request, Lead $lead, AccessService $access): void
    {
        $access->franchise($request->user(), (int) $lead->originating_franchise_id);
        abort_unless(Lead::visibleTo($request->user())->whereKey($lead->id)->whereNotNull('handled_by')->exists(), 403, 'Assuma o lead disponível para abrir a conversa. Leads de outras pessoas não podem ser acessados.');
    }

    private function connection(Request $request): DigisacConnection
    {
        $connection = DigisacConnection::where('user_id', $request->user()->id)->first();
        abort_unless($connection?->verified_at, 422, 'Configure e valide sua conexão com o Digisac antes de conversar.');
        return $connection;
    }

    public function index(Request $request, AccessService $access, ?Lead $lead = null)
    {
        $data = $request->validate(['q' => 'nullable|string|max:80', 'fila' => 'nullable|boolean', 'todas' => 'nullable|boolean']);
        if ($lead) {
            abort_unless($this->available($request, $access)->whereKey($lead->id)->exists(), 403);
            $access->franchise($request->user(), (int) $lead->originating_franchise_id);
        }
        $queue = $request->boolean('fila');
        $seesAll = in_array($request->user()->role, Lead::SEE_ALL, true);
        $all = $seesAll && ! $queue && $request->boolean('todas');
        $query = $this->available($request, $access)->with('handler:id,name')
            ->when($queue, fn ($q) => $q->whereNull('handled_by'))
            ->when($all, fn ($q) => $q->whereNotNull('handled_by'))
            ->when(! $queue && ! $all, fn ($q) => $q->where('handled_by', $request->user()->id));
        $search = trim($data['q'] ?? '');
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('phone', 'like', $like));
        }
        return response()->view('leads.chat', [
            'leads' => $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(25)->withQueryString(),
            'selected' => $lead, 'queue' => $queue, 'all' => $all, 'seesAll' => $seesAll, 'search' => $search,
            'connection' => DigisacConnection::where('user_id', $request->user()->id)->first(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function claim(Request $request, Lead $lead, AccessService $access)
    {
        $access->franchise($request->user(), (int) $lead->originating_franchise_id);
        abort_unless(DigisacClient::phone($lead->phone), 422, 'Este lead precisa de um telefone válido para usar o chat.');
        // Uma única pessoa ganha a disputa pelo lead da fila.
        Lead::whereKey($lead->id)->whereNull('handled_by')->update(['handled_by' => $request->user()->id]);
        $this->owned($request, $lead, $access);
        return redirect()->route('leads.chat.show', $lead);
    }

    public function messages(Request $request, Lead $lead, AccessService $access, DigisacClient $client)
    {
        $this->readable($request, $lead, $access);
        $data = $request->validate(['page' => 'nullable|integer|min:1|max:10000']);
        $connection = $this->connection($request);
        $phone = DigisacClient::phone($lead->phone);
        abort_unless($phone, 422, 'O lead precisa de um telefone válido para conversar.');
        $contact = $client->contact($connection, $phone);
        $result = $contact ? $client->messages($connection, (string) $contact['id'], (int) ($data['page'] ?? 1))
            : ['messages' => [], 'has_more' => false];
        // Revalida caso o responsável tenha sido trocado durante a consulta externa.
        $this->readable($request, $lead, $access);
        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request, Lead $lead, AccessService $access, DigisacClient $client)
    {
        $this->owned($request, $lead, $access);
        $data = $request->validate(['text' => 'required|string|max:4096', 'request_id' => 'required|uuid']);
        $text = trim($data['text']);
        abort_if($text === '', 422, 'Escreva uma mensagem antes de enviar.');
        $connection = $this->connection($request);
        $phone = DigisacClient::phone($lead->phone);
        abort_unless($phone, 422, 'O lead precisa de um telefone válido para conversar.');
        $key = 'digisac:send:'.$request->user()->id.':'.$lead->id.':'.$data['request_id'];
        if (Cache::get($key) === 'sent') return response()->json(['sent' => true]);
        $contact = $client->contact($connection, $phone);
        $this->owned($request, $lead, $access);
        // Reserva antes de enviar. Uma falha de rede pode ocorrer depois da entrega remota.
        abort_unless(Cache::add($key, 'pending', now()->addDay()), 409, 'Este envio já foi iniciado. Confira a conversa antes de tentar novamente.');
        $client->send($connection, isset($contact['id']) ? (string) $contact['id'] : null, $phone, $text);
        Cache::put($key, 'sent', now()->addDay());
        $lead->refresh();
        Lead::whereKey($lead->id)->where('handled_by', $request->user()->id)->update([
            'contacted_at' => $lead->contacted_at ?? now(), 'status' => $lead->status === 'new' ? 'contacted' : $lead->status,
        ]);
        return response()->json(['sent' => true]);
    }
}

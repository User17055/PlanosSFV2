<?php

namespace App\Http\Controllers;

use App\Models\{DigisacConnection, Lead};
use App\Services\AccessService;
use App\Services\Integrations\{DigisacClient, LeadPhotoSync};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, Storage};

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

    /** Foto guardada do contato: quem vê o lead na lista vê a foto. */
    public function photo(Request $request, Lead $lead, AccessService $access)
    {
        abort_unless($this->available($request, $access)->whereKey($lead->id)->exists(), 403);
        abort_unless($lead->photo_path && Storage::disk('local')->exists($lead->photo_path), 404);
        return Storage::disk('local')->response($lead->photo_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function messages(Request $request, Lead $lead, AccessService $access, DigisacClient $client, LeadPhotoSync $photos)
    {
        $this->readable($request, $lead, $access);
        $data = $request->validate(['page' => 'nullable|integer|min:1|max:10000']);
        $connection = $this->connection($request);
        $phone = DigisacClient::phone($lead->phone);
        abort_unless($phone, 422, 'O lead precisa de um telefone válido para conversar.');
        $contact = $client->contact($connection, $phone);
        $result = $contact ? $client->messages($connection, (string) $contact['id'], (int) ($data['page'] ?? 1))
            : ['messages' => [], 'has_more' => false];
        // Abrir a conversa também atualiza a foto guardada, no máximo uma vez por dia. Falha na foto nunca derruba a conversa.
        if ($contact && (! $lead->photo_synced_at || $lead->photo_synced_at->lt(now()->subDay()))) rescue(fn () => $photos->store($lead, $contact), report: false);
        $result['avatar'] = $lead->photo_path ? $lead->photoUrl()
            : ($contact ? (DigisacClient::fileUrl($contact['thumbAvatar'] ?? null) ?? DigisacClient::fileUrl($contact['avatar'] ?? null)) : null);
        // Revalida caso o responsável tenha sido trocado durante a consulta externa.
        $this->readable($request, $lead, $access);
        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    /** Tipo lido do próprio conteúdo, nunca do navegador. O áudio gravado no Chrome é MP4 só de som, que o finfo chama de vídeo. */
    private function attachment(array $file): array
    {
        $bytes = base64_decode($file['data'], true);
        abort_if($bytes === false || $bytes === '', 422, 'Não foi possível ler o arquivo. Anexe de novo.');
        abort_if(strlen($bytes) > 5 * 1024 * 1024, 422, 'O arquivo passa de 5 MB. Envie um arquivo menor.');
        $name = mb_substr(trim(preg_replace('/[^\pL\pN ._-]+/u', '', basename($file['name']))), 0, 120) ?: 'arquivo';
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (in_array($type, ['video/mp4', 'audio/x-m4a'], true) && str_ends_with(strtolower($name), '.m4a')) $type = 'audio/mp4';
        if ($type === 'video/webm' && str_ends_with(strtolower($name), '.weba')) $type = 'audio/webm';
        abort_unless(in_array($type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/aac', 'audio/webm', 'video/mp4', 'application/pdf'], true),
            422, 'Envie foto, áudio, vídeo MP4 ou PDF.');
        return ['base64' => base64_encode($bytes), 'mimetype' => $type, 'name' => $name];
    }

    public function send(Request $request, Lead $lead, AccessService $access, DigisacClient $client)
    {
        $this->owned($request, $lead, $access);
        $data = $request->validate(['text' => 'nullable|string|max:4096|required_without:file', 'request_id' => 'required|uuid',
            'file' => 'nullable|array:data,name', 'file.data' => 'required_with:file|string|max:7000000', 'file.name' => 'required_with:file|string|max:120']);
        $text = trim($data['text'] ?? '');
        $file = isset($data['file']) ? $this->attachment($data['file']) : null;
        abort_if($text === '' && ! $file, 422, 'Escreva uma mensagem antes de enviar.');
        $connection = $this->connection($request);
        $phone = DigisacClient::phone($lead->phone);
        abort_unless($phone, 422, 'O lead precisa de um telefone válido para conversar.');
        $key = 'digisac:send:'.$request->user()->id.':'.$lead->id.':'.$data['request_id'];
        if (Cache::get($key) === 'sent') return response()->json(['sent' => true]);
        $contact = $client->contact($connection, $phone);
        $this->owned($request, $lead, $access);
        // Reserva antes de enviar. Uma falha de rede pode ocorrer depois da entrega remota.
        abort_unless(Cache::add($key, 'pending', now()->addDay()), 409, 'Este envio já foi iniciado. Confira a conversa antes de tentar novamente.');
        $client->send($connection, isset($contact['id']) ? (string) $contact['id'] : null, $phone, $text, $file);
        Cache::put($key, 'sent', now()->addDay());
        $lead->refresh();
        Lead::whereKey($lead->id)->where('handled_by', $request->user()->id)->update([
            'contacted_at' => $lead->contacted_at ?? now(), 'status' => $lead->status === 'new' ? 'contacted' : $lead->status,
        ]);
        return response()->json(['sent' => true]);
    }
}

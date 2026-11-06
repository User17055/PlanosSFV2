<?php

namespace App\Services\Integrations;

use App\Models\{DigisacConnection, Lead};
use App\Services\Domain\DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{DB, Http, Storage};

/** Guarda a foto do contato do Digisac junto do lead, para ela aparecer nas listas sem abrir a conversa. */
class LeadPhotoSync
{
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private DigisacClient $client) {}

    /** O link do Digisac é temporário, então a imagem é baixada. Sem foto ou com arquivo que não é imagem, só fica registrada a consulta. */
    public function store(Lead $lead, ?array $contact): void
    {
        $url = $contact ? (DigisacClient::fileUrl($contact['thumbAvatar'] ?? null) ?? DigisacClient::fileUrl($contact['avatar'] ?? null)) : null;
        $path = $lead->photo_path;
        if ($url) {
            try {
                $response = Http::connectTimeout(5)->timeout(15)->withoutRedirecting()->get($url);
            } catch (ConnectionException) {
                $response = null;
            }
            $bytes = $response?->successful() ? $response->body() : '';
            $extension = $bytes !== '' && strlen($bytes) <= 2 * 1024 * 1024 ? (self::TYPES[(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes)] ?? null) : null;
            if ($extension) {
                $path = 'lead-photos/'.$lead->id.'.'.$extension;
                Storage::disk('local')->put($path, $bytes);
                if ($lead->photo_path && $lead->photo_path !== $path) Storage::disk('local')->delete($lead->photo_path);
            }
        }
        $values = ['photo_path' => $path, 'photo_synced_at' => now()];
        // Direto na tabela: a foto não mexe em updated_at, que ordena a lista de conversas.
        DB::table('leads')->where('id', $lead->id)->update($values);
        $lead->forceFill($values)->syncOriginal();
    }

    /**
     * Leads com telefone e foto não conferida há 7 dias, mais novos primeiro. Usa a conexão do atendente do lead;
     * sem ela (ou na fila), a de um administrador central, que já pode ler todas as conversas.
     */
    public function syncPending(int $limit = 30): int
    {
        $admin = DigisacConnection::whereNotNull('verified_at')->whereHas('user', fn ($q) => $q->where('role', 'central_admin')->where('active', true))->orderBy('id')->first();
        $leads = Lead::whereNotNull('phone')->where(fn ($q) => $q->whereNull('photo_synced_at')->orWhere('photo_synced_at', '<', now()->subDays(7)))
            ->orderByDesc('id')->limit($limit)->get();
        $checked = 0;
        foreach ($leads as $lead) {
            $connection = ($lead->handled_by ? DigisacConnection::where('user_id', $lead->handled_by)->whereNotNull('verified_at')->first() : null) ?? $admin;
            $phone = DigisacClient::phone($lead->phone);
            try {
                // Sem conexão ou telefone, a consulta fica registrada para o lead não ocupar a vez dos outros.
                $this->store($lead, $connection && $phone ? $this->client->contact($connection, $phone) : null);
                $checked++;
            } catch (DomainException) {
                // Digisac fora do ar ou contato repetido: fica para a próxima rodada.
            }
        }
        return $checked;
    }
}

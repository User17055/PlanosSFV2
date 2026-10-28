<?php

namespace App\Services\Integrations;

use App\Jobs\ProcessVindiWebhookJob;
use App\Models\IntegrationEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VindiWebhookService
{
    public function receive(Request $request): IntegrationEvent
    {
        $this->authenticate($request);
        if (strlen($request->getContent()) > 262144) {
            throw new HttpException(413, 'Notificação excede o limite de tamanho.');
        }
        $payload = $request->json()->all();
        $type = data_get($payload, 'event.type');
        if (! is_string($type) || ! preg_match('/^[a-z_]{1,80}$/D', $type)) {
            throw ValidationException::withMessages(['event' => 'Evento Vindi inválido.']);
        }
        $bill = data_get($payload, 'event.data.bill.id') ?? data_get($payload, 'event.data.charge.bill.id');
        $subscription = data_get($payload, 'event.data.subscription.id');
        foreach ([$bill, $subscription] as $identifier) {
            if ($identifier !== null && ! preg_match('/^[0-9]+$/D', (string) $identifier)) {
                throw ValidationException::withMessages(['event' => 'Referência externa inválida.']);
            }
        }
        $account = (string) config('integrations.vindi.provider_account', 'central');
        $externalId = data_get($payload, 'event.id');
        $fingerprint = hash('sha256', $request->getContent());
        // When no provider event ID exists, deduplicate exact bodies only. A newer
        // notification for the same invoice is allowed and triggers a fresh GET.
        $key = 'vindi:'.$account.':'.hash('sha256', $externalId ? 'id:'.$externalId : 'body:'.$fingerprint);
        $event = DB::transaction(fn () => IntegrationEvent::firstOrCreate(['idempotency_key' => $key], [
            'provider' => 'vindi', 'provider_account' => $account,
            'external_event_id' => $externalId === null ? null : substr((string) $externalId, 0, 191),
            'event_type' => $type, 'entity_type' => $bill ? 'invoice' : ($subscription ? 'subscription' : null),
            'entity_id' => $bill ? (string) $bill : ($subscription ? (string) $subscription : null),
            // Deliberately retain references rather than card/contact details.
            'payload' => ['type' => $type, 'bill_id' => $bill, 'subscription_id' => $subscription,
                'provider_created_at' => data_get($payload, 'event.created_at'), 'body_sha256' => $fingerprint],
            'status' => 'pending', 'received_at' => now(),
        ]));
        if ($event->status === 'pending' || $event->status === 'error') {
            ProcessVindiWebhookJob::dispatch($event->id)->afterCommit();
        }
        return $event;
    }

    private function authenticate(Request $request): void
    {
        $username = (string) config('integrations.vindi.webhook_user');
        $password = (string) config('integrations.vindi.webhook_password');
        if (! config('integrations.vindi.enabled') || $username === '' || strlen($password) < 24) {
            throw new HttpException(503, 'Recebimento Vindi ainda não configurado.');
        }
        $providedUser = (string) $request->getUser();
        $providedPassword = (string) $request->getPassword();
        $header = $request->header('Authorization', '');
        if (str_starts_with($header, 'Basic ')) {
            $credentials = base64_decode(substr($header, 6), true);
            if ($credentials !== false && str_contains($credentials, ':')) {
                [$providedUser, $providedPassword] = explode(':', $credentials, 2);
            }
        }
        if (! hash_equals($username, $providedUser) || ! hash_equals($password, $providedPassword)) {
            throw new HttpException(401, 'Autenticação da notificação inválida.', null, ['WWW-Authenticate' => 'Basic realm="Vindi webhook"']);
        }
    }
}

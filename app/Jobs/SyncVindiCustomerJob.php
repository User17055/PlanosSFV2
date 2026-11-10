<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\CustomerAddress;
use App\Services\Integrations\VindiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/** Leva o CEP, o endereço e o telefone do cadastro daqui para o cliente da Vindi. Repetir não faz mal: manda os dados atuais. */
class SyncVindiCustomerJob implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 5;
    public int $timeout = 60;

    public function __construct(public int $customerId)
    {
        $this->onQueue('vindi');
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    public function middleware(): array
    {
        return [new RateLimited('vindi')];
    }

    public function handle(VindiClient $client): void
    {
        $customer = Customer::find($this->customerId);
        if (! $customer?->vindi_customer_id || ! $client->configured() || ! config('integrations.vindi.allow_creation')) {
            return;
        }
        $payload = array_filter(['address' => CustomerAddress::vindi($customer->address)]);
        if ($phone = VindiClient::phone($customer->phone)) {
            // Telefone sem id é acrescentado na Vindi: o número que já está lá não vai de novo, e um número antigo é trocado.
            $current = collect($client->customer($customer->vindi_customer_id)['phones'] ?? []);
            if (! $current->contains(fn ($saved) => preg_replace('/\D/', '', (string) ($saved['number'] ?? '')) === $phone['number'])) {
                $payload['phones'] = [array_filter(['id' => $current->first()['id'] ?? null]) + $phone];
            }
        }
        if ($payload) {
            $client->updateCustomer($customer->vindi_customer_id, $payload);
        }
    }
}

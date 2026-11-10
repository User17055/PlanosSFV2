<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\CustomerAddress;
use App\Services\Integrations\VindiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/** Leva o CEP e o endereço do cadastro daqui para o cliente da Vindi. Repetir não faz mal: manda o endereço atual. */
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
        $address = CustomerAddress::vindi($customer?->address);
        if (! $customer?->vindi_customer_id || ! $address || ! $client->configured() || ! config('integrations.vindi.allow_creation')) {
            return;
        }
        $client->updateCustomer($customer->vindi_customer_id, ['address' => $address]);
    }
}

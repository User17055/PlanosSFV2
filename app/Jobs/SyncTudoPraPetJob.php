<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\Integrations\TudoPraPetMembership;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Coloca ou tira o tutor do grupo da Tudo pra Pet; falha temporária é repetida com espera crescente. */
class SyncTudoPraPetJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public int $customerId, public bool $force = false)
    {
        $this->onQueue('tudoprapet');
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600, 7200];
    }

    public function handle(TudoPraPetMembership $membership): void
    {
        $customer = Customer::find($this->customerId);
        if ($customer) {
            $membership->sync($customer, $this->force);
        }
    }

    public function failed(\Throwable $exception): void
    {
        // A conferência diária tenta de novo; aqui só fica o motivo visível para a equipe.
        Customer::whereKey($this->customerId)->update(['tudoprapet_error' => 'Tudo pra Pet indisponível; nova tentativa na conferência diária.']);
    }
}

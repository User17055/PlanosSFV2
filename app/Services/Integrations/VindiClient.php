<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/** Vindi Service API v1. Read retries are safe; creation is never automatically retried. */
class VindiClient
{
    public const PER_PAGE = 50;

    /** Causa de uma falha para o log: tipo, código HTTP e local. Nunca a mensagem nem o corpo da resposta, que podem trazer dados de cliente. */
    public static function failureReason(\Throwable $exception): array
    {
        return [
            'tipo' => class_basename($exception),
            'http' => $exception instanceof RequestException ? $exception->response->status() : null,
            'local' => basename($exception->getFile()).':'.$exception->getLine(),
        ];
    }

    public function configured(): bool
    {
        return (bool) config('integrations.vindi.enabled') && filled(config('integrations.vindi.api_key'));
    }

    public function bill(string $id): array
    {
        $this->assertIdentifier($id);
        return $this->request()->get('bills/'.$id)->throw()->json('bill') ?? [];
    }

    public function subscription(string $id): array
    {
        $this->assertIdentifier($id);
        return $this->request()->get('subscriptions/'.$id)->throw()->json('subscription') ?? [];
    }

    /** Uma página de uma listagem da API (plans, subscriptions, customers). */
    public function page(string $resource, int $page, ?string $query = null): array
    {
        return $this->request()->get($resource, array_filter(['query' => $query, 'page' => $page, 'per_page' => self::PER_PAGE]))->throw()->json($resource) ?? [];
    }

    public function billsForSubscription(string $id, int $page = 1): array
    {
        $this->assertIdentifier($id);
        return $this->request()->get('bills', [
            'query' => 'subscription_id='.$id, 'page' => max(1, $page), 'per_page' => 50,
        ])->throw()->json('bills') ?? [];
    }

    public function customersByCode(string $code): array
    {
        return $this->request()->get('customers', ['query' => 'code="'.str_replace('"', '', $code).'"'])->throw()->json('customers') ?? [];
    }

    /** Searches the CPF both as digits and formatted, since either form may have been saved in Vindi. */
    public function customersByRegistryCode(string $cpf): array
    {
        $digits = preg_replace('/\D/', '', $cpf);
        $formatted = preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/D', '$1.$2.$3-$4', $digits);
        $found = [];
        foreach (array_unique([$digits, $formatted]) as $value) {
            foreach ($this->request()->get('customers', ['query' => 'registry_code="'.$value.'"'])->throw()->json('customers') ?? [] as $customer) {
                $found[(string) ($customer['id'] ?? '')] = $customer;
            }
        }
        return array_values($found);
    }

    public function createCustomer(array $payload): array
    {
        $this->assertCreationEnabled();
        return $this->request()->post('customers', $payload)->throw()->json('customer') ?? [];
    }

    public function billsByCode(string $code): array
    {
        return $this->request()->get('bills', ['query' => 'code="'.str_replace('"', '', $code).'"'])->throw()->json('bills') ?? [];
    }

    public function createBill(array $payload): array
    {
        $this->assertCreationEnabled();
        return $this->request()->post('bills', $payload)->throw()->json('bill') ?? [];
    }

    public function plan(string $id): array
    {
        $this->assertIdentifier($id);
        return $this->request()->get('plans/'.$id)->throw()->json('plan') ?? [];
    }

    public function subscriptionsByCode(string $code): array
    {
        return $this->request()->get('subscriptions',['query'=>'code="'.str_replace('"','',$code).'"'])->throw()->json('subscriptions') ?? [];
    }

    /** Todas as assinaturas de um cliente da Vindi; quem chama filtra a situação. */
    public function subscriptionsOfCustomer(string $customerId): array
    {
        $this->assertIdentifier($customerId);
        return $this->request()->get('subscriptions',['query'=>'customer_id='.$customerId,'per_page'=>50])->throw()->json('subscriptions') ?? [];
    }

    public function customer(string $id): array
    {
        $this->assertIdentifier($id);
        return $this->request()->get('customers/'.$id)->throw()->json('customer') ?? [];
    }

    public function createSubscription(array $payload): array
    {
        $this->assertCreationEnabled();
        return $this->request()->post('subscriptions', $payload)->throw()->json('subscription') ?? [];
    }

    public function cancelSubscription(string $id): array
    {
        $this->assertCreationEnabled();
        $this->assertIdentifier($id);
        return $this->request()->delete('subscriptions/'.$id)->throw()->json('subscription') ?? [];
    }

    private function request(): PendingRequest
    {
        if (! $this->configured()) {
            throw new IntegrationPendingException('Vindi desabilitada ou sem credencial. Nenhuma cobrança foi emitida.');
        }
        $url = rtrim((string) config('integrations.vindi.base_url'), '/').'/';
        if (! str_starts_with($url, 'https://')) {
            throw new IntegrationPendingException('A API Vindi exige HTTPS.');
        }
        return Http::baseUrl($url)->withBasicAuth((string) config('integrations.vindi.api_key'), '')
            ->acceptJson()->asJson()->connectTimeout(5)->timeout((int) config('integrations.vindi.timeout', 15));
    }

    private function assertCreationEnabled(): void
    {
        if (! config('integrations.vindi.allow_creation')) {
            throw new IntegrationPendingException('Emissão Vindi pendente de homologação da conta e do mapeamento comercial.');
        }
    }

    private function assertIdentifier(string $id): void
    {
        if (! preg_match('/^[0-9]+$/D', $id)) {
            throw new \InvalidArgumentException('Identificador Vindi inválido.');
        }
    }
}

<?php

namespace App\Services\Integrations;

use App\Http\Controllers\CustomerController;
use App\Models\{Customer,Franchise};
use App\Services\CustomerAddress;
use App\Services\Domain\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Traz da Vindi o cadastro de quem tem plano ativo. Não cria pet, contrato nem assinatura, e só faz GET na Vindi.
 * Com a base de 11/2026 são cerca de 90 chamadas, abaixo do limite de 120 por minuto. Rodar de novo não duplica.
 */
class VindiCustomerImport
{
    public const ARCHIVED = 'Cliente inativo ou arquivado na Vindi';
    public const INVALID = 'Sem nome ou com CPF ausente ou inválido, inclusive CNPJ';
    public const REPEATED = 'CPF repetido em mais de um cliente na Vindi';
    public const CONFLICT = 'Conflito com um cadastro que já existe aqui';

    public function __construct(private VindiClient $client, private CustomerController $customers) {}

    public function run(bool $apply): array
    {
        // Lê tudo antes de gravar: se a Vindi falhar no meio, nada fica pela metade.
        $unit = Franchise::where('code', 'CENTRAL')->where('active', true)->firstOrFail();
        [$plans, $excluded] = collect($this->all('plans'))->partition(fn ($plan) => self::isPlan((string) ($plan['name'] ?? '')));
        $perPlan = [];
        $ids = [];
        foreach ($plans as $plan) {
            $subscriptions = $this->all('subscriptions', 'status:active plan_id:'.$plan['id']);
            $perPlan[$plan['name']] = count($subscriptions);
            foreach ($subscriptions as $subscription) {
                $ids[(string) data_get($subscription, 'customer.id')] = true;
            }
        }
        unset($ids['']);
        $active = $this->all('customers', 'status:active');

        $report = ['plans' => $perPlan, 'excluded' => $excluded->pluck('name')->values()->all(), 'found' => count($ids), 'created' => 0, 'linked' => 0, 'existing' => 0, 'filled' => ['email' => 0, 'phone' => 0, 'address' => 0],
            'skipped' => [self::ARCHIVED => [], self::INVALID => [], self::REPEATED => [], self::CONFLICT => []]];
        $byCpf = [];
        foreach (array_map('strval', array_keys($ids)) as $id) {
            // A listagem deixou de fora clientes ativos (46 em 11/2026), então quem não veio nela é consultado direto.
            $person = $active[$id] ?? $this->client->customer($id);
            if (($person['status'] ?? null) !== 'active') {
                $report['skipped'][self::ARCHIVED][] = $id;
            } elseif (($cpf = $this->validCpf($person)) === null) {
                $report['skipped'][self::INVALID][] = $id;
            } else {
                $byCpf[$cpf][] = $person;
            }
        }

        foreach ($byCpf as $cpf => $people) {
            $cpf = (string) $cpf;
            // Com o mesmo CPF em dois clientes, só uma pessoa sabe qual é o certo.
            if (count($people) > 1) {
                array_push($report['skipped'][self::REPEATED], ...array_map(fn ($person) => (string) $person['id'], $people));
                continue;
            }
            $person = $people[0];
            $vindiId = (string) $person['id'];
            $matches = Customer::where('document', $cpf)->orWhere('vindi_customer_id', $vindiId)->get();
            $local = $matches->first();
            if ($matches->count() > 1 || ($local && ($local->document !== $cpf || ($local->vindi_customer_id && $local->vindi_customer_id !== $vindiId)))) {
                $report['skipped'][self::CONFLICT][] = $vindiId;
            } elseif ($local?->vindi_customer_id) {
                $report['existing']++;
            } elseif ($local) {
                $report['linked']++;
                if ($apply) {
                    DB::transaction(function () use ($local, $vindiId) {
                        $local->update(['vindi_customer_id' => $vindiId]);
                        Audit::record('customer.linked_to_vindi', $local, null, ['vindi_customer_id' => $vindiId]);
                    });
                }
            } else {
                $report['created']++;
                $fields = $this->fields($person);
                // Conta antes de gravar: rodar de novo não completa cadastro que já existe, então um campo vazio aqui precisa aparecer na simulação.
                foreach (array_keys($report['filled']) as $field) {
                    $report['filled'][$field] += $fields[$field] ? 1 : 0;
                }
                if ($apply) {
                    DB::transaction(function () use ($fields, $cpf, $vindiId, $unit) {
                        $customer = Customer::create($fields + ['document' => $cpf, 'vindi_customer_id' => $vindiId, 'originating_franchise_id' => $unit->id]);
                        Audit::record('customer.imported_from_vindi', $customer, null, ['vindi_customer_id' => $vindiId]);
                    });
                }
            }
        }
        return $report;
    }

    /** Fica só o que é plano: pacotes, serviços, cobranças avulsas e testes não entram. */
    public static function isPlan(string $name): bool
    {
        return preg_match('/^\s*plano\b/iu', $name) === 1 && preg_match('/teste/iu', $name) !== 1;
    }

    private function all(string $resource, ?string $query = null): array
    {
        $items = [];
        for ($page = 1; $page <= 100; $page++) {
            $rows = $this->client->page($resource, $page, $query);
            foreach ($rows as $row) {
                $items[(string) $row['id']] = $row;
            }
            if (count($rows) < VindiClient::PER_PAGE) {
                return $items;
            }
        }
        throw new IntegrationPendingException('Limite de páginas atingido na leitura da Vindi; nada foi gravado.');
    }

    private function validCpf(array $person): ?string
    {
        $cpf = preg_replace('/\D/', '', (string) ($person['registry_code'] ?? ''));
        if (mb_strlen(trim((string) ($person['name'] ?? ''))) < 3) {
            return null;
        }
        try {
            $this->customers->validateCpf($cpf);
        } catch (ValidationException) {
            return null;
        }
        return $cpf;
    }

    private function fields(array $person): array
    {
        $email = trim((string) ($person['email'] ?? ''));
        $phones = collect($person['phones'] ?? []);
        $address = (array) ($person['address'] ?? []);
        $state = strtoupper(trim((string) ($address['state'] ?? '')));
        return [
            'name' => mb_substr(trim($person['name']), 0, 180),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 180 ? $email : null,
            'phone' => self::phone((string) data_get($phones->firstWhere('phone_type', 'mobile') ?? $phones->first(), 'number')),
            'address' => CustomerAddress::from(array_filter([
                'street' => $address['street'] ?? null, 'number' => $address['number'] ?? null, 'complement' => $address['additional_details'] ?? null,
                'neighborhood' => $address['neighborhood'] ?? null, 'city' => $address['city'] ?? null,
                'state' => strlen($state) === 2 ? $state : null, 'zip' => $address['zipcode'] ?? null,
            ])) ?: null,
        ];
    }

    /** A Vindi guarda com o 55 na frente; aqui o telefone fica como a equipe digita. */
    private static function phone(string $number): ?string
    {
        $digits = preg_replace('/\D/', '', $number);
        if (strlen($digits) > 11 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }
        return preg_match('/^(\d{2})(\d{4,5})(\d{4})$/D', $digits, $m) ? "($m[1]) $m[2]-$m[3]" : (substr($digits, 0, 30) ?: null);
    }
}

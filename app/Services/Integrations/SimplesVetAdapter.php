<?php

namespace App\Services\Integrations;

use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * Leva o plano para o SimplesVet pelo robô (rpa/simplesvet/robo.mjs): plano ativo põe "CLIENTE PLANO" no tutor e
 * "<PLANO> - dd/mm/aaaa" no pet, cadastrando o que faltar; plano encerrado tira as marcas.
 */
class SimplesVetAdapter implements PartnerAdapter
{
    public const TUTOR_TAG = 'CLIENTE PLANO';

    public function enabled(): bool
    {
        return (bool) config('integrations.simplesvet.enabled') && filled(config('integrations.simplesvet.user')) && filled(config('integrations.simplesvet.password'));
    }

    public function synchronize(Subscription $subscription, bool $dryRun = false): array
    {
        if (! $this->enabled()) {
            throw new IntegrationPendingException('SimplesVet: tarefa manual necessária. O robô está desligado ou sem a conta configurada.');
        }
        $subscription = $subscription->fresh(['customer', 'pet', 'productVersion']);
        $action = $subscription->status === 'active' ? 'activate' : ($subscription->isTerminal() ? 'deactivate' : null);
        if (! $action) {
            return ['ok' => true, 'actions' => ['contrato ainda não está ativo: nada a fazer no SimplesVet']];
        }
        // Um robô por vez: duas sessões da mesma conta no SimplesVet se atrapalham.
        $lock = Cache::lock('simplesvet-robo', 600);
        if (! $lock->get()) {
            throw new \RuntimeException('Robô do SimplesVet ocupado; nova tentativa em seguida.');
        }
        try {
            $result = $this->run($this->task($subscription, $action, $dryRun));
        } finally {
            $lock->release();
        }
        if (! ($result['ok'] ?? false)) {
            if ($result['manual'] ?? false) {
                throw new IntegrationPendingException('SimplesVet: '.($result['error'] ?? 'conferência manual necessária.'));
            }
            throw new \RuntimeException('SimplesVet: '.($result['error'] ?? 'o robô não concluiu.'));
        }
        if (! $dryRun) {
            if (! empty($result['tutor_id'])) $subscription->customer->update(['simplesvet_id' => (string) $result['tutor_id']]);
            if (! empty($result['pet_id'])) $subscription->pet->update(['simplesvet_id' => (string) $result['pet_id']]);
        }

        return $result;
    }

    public function task(Subscription $s, string $action, bool $dryRun): array
    {
        $customer = $s->customer;
        $pet = $s->pet;
        $address = is_array($customer->address) ? $customer->address : [];
        $plan = mb_strtoupper($s->productVersion->name);
        $since = ($s->activated_at ?? now())->timezone('America/Sao_Paulo')->format('d/m/Y');

        return [
            'action' => $action, 'dry_run' => $dryRun,
            'tutor' => ['cpf' => $customer->document, 'name' => $customer->name, 'sex' => $customer->sex, 'email' => $customer->email, 'phone' => $customer->phone,
                'origin' => self::origin(data_get($customer->acquisition_profile, 'acquisition_source')), 'simplesvet_id' => $customer->simplesvet_id,
                'address' => array_map(fn ($key) => $address[$key] ?? null, ['zip' => 'zip', 'street' => 'street', 'number' => 'number', 'complement' => 'complement',
                    'neighborhood' => 'neighborhood', 'city' => 'city', 'state' => 'state'])],
            'pet' => ['name' => $pet->name, 'species' => $pet->species, 'sex' => $pet->sex, 'neutered' => $pet->neutered, 'birth_date' => $pet->birth_date?->format('Y-m-d'),
                'breed' => $pet->breed, 'color' => $pet->color, 'simplesvet_id' => $pet->simplesvet_id],
            // "PLANO ECONÔMICO FELINO - 10/11/2026" (formato pedido pelo usuário); o robô também reconhece o antigo "... DESDE ...".
            'tutor_tag' => self::TUTOR_TAG, 'pet_tag' => "$plan - $since", 'pet_tag_prefix' => $plan,
            // A marca do tutor só sai quando ele não tem mais nenhum plano ativo.
            'keep_tutor_tag' => Subscription::where('customer_id', $customer->id)->whereKeyNot($s->id)->where('status', 'active')->exists(),
        ];
    }

    /** "Como nos conheceu?" do SimplesVet; site, link da equipe e sem origem contam como Google (decisão do usuário). */
    public static function origin(?string $source): string
    {
        return ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'google' => 'Google', 'referral' => 'Indicação de amigo'][$source] ?? 'Google';
    }

    private function run(array $task): array
    {
        $private = storage_path('app/private');
        $result = Process::path(base_path('rpa/simplesvet'))->timeout((int) config('integrations.simplesvet.timeout', 200))
            ->env(['SIMPLESVET_USER' => (string) config('integrations.simplesvet.user'), 'SIMPLESVET_PASSWORD' => (string) config('integrations.simplesvet.password'),
                'PLAYWRIGHT_BROWSERS_PATH' => (string) config('integrations.simplesvet.browsers'), 'SV_STATE_FILE' => $private.'/simplesvet-sessao.json', 'HOME' => $private])
            ->input(json_encode($task, JSON_UNESCAPED_UNICODE))
            ->run([(string) config('integrations.simplesvet.node', 'node'), 'robo.mjs']);
        $data = json_decode((string) collect(explode("\n", trim($result->output())))->last(), true);
        if (! is_array($data)) {
            throw new \RuntimeException('SimplesVet: o robô não respondeu (saída '.$result->exitCode().').');
        }

        return $data;
    }
}

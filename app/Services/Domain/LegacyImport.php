<?php

namespace App\Services\Domain;

use App\Models\{Customer, Franchise, LegacyPendingItem, Pet, PetProductSlot, Product, ProductVersion, Proposal, Subscription, User};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Base antiga (Vindi + SimplesVet): cada item ativo da Vindi vira um contrato ativo, já ligado à assinatura e ao item de lá,
 * num plano "base antiga" que não é vendido. Sem entrada, sem comissão e sem e-mail (imported_at). O contrato escaneado entra depois pelo fluxo de papel.
 */
class LegacyImport
{
    private array $versions = [];

    /** Sem $apply, faz tudo dentro de uma transação desfeita: os números são os mesmos que a gravação daria. */
    public function run(array $data, User $actor, bool $apply): array
    {
        $report = array_fill_keys(['clientes_criados', 'clientes_completados', 'clientes_ja_existentes', 'pets_criados', 'pets_ja_existentes',
            'contratos_criados', 'pets_para_escolher', 'itens_ja_importados', 'planos_base_antiga'], 0);
        $unit = Franchise::where('code', 'CENTRAL')->firstOrFail();
        DB::beginTransaction();
        try {
            $plans = collect($data['plans'] ?? [])->keyBy('name');
            foreach ($data['customers'] ?? [] as $row) {
                $customer = $this->customer($row, $unit, $report);
                $pets = [];
                foreach ($row['pets'] ?? [] as $petRow) {
                    $pets[(string) $petRow['simplesvet_id']] = $this->pet($customer, $petRow, $report);
                }
                foreach ($row['items'] ?? [] as $item) {
                    $itemId = (string) $item['vindi_product_item_id'];
                    if (Subscription::where('vindi_product_item_id', $itemId)->exists() || LegacyPendingItem::where('vindi_product_item_id', $itemId)->exists()) {
                        $report['itens_ja_importados']++;
                        continue;
                    }
                    $version = $this->version($plans[$item['plan']] ?? ['name' => $item['plan'], 'category' => 'health_plan', 'price_cents' => $item['price_cents']], $report);
                    $pet = $pets[(string) ($item['pet_simplesvet_id'] ?? '')] ?? null;
                    try {
                        if (! $pet) {
                            throw new DomainException('PET_NOT_CHOSEN', $item['reason'] ?? 'escolher o pet');
                        }
                        DB::transaction(fn () => $this->contract($customer, $pet, $version, $item, $actor, $unit));
                        $report['contratos_criados']++;
                    } catch (DomainException $e) {
                        LegacyPendingItem::create(['customer_id' => $customer->id, 'product_version_id' => $version->id,
                            'vindi_subscription_id' => (string) $item['vindi_subscription_id'], 'vindi_product_item_id' => $itemId,
                            'price_cents' => (int) $item['price_cents'], 'started_at' => $this->start($item)->toDateString(),
                            'reason' => $e->errorCode === 'PET_NOT_CHOSEN' ? $e->getMessage() : 'o pet indicado já tem plano desta categoria',
                            'hint' => isset($item['hint']) ? Str::limit((string) $item['hint'], 250) : null]);
                        $report['pets_para_escolher']++;
                    }
                }
            }
            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $this->versions = [];
        return $report;
    }

    /** Pet escolhido pela equipe para um item que ficou sem pet. */
    public function link(LegacyPendingItem $item, Pet $pet, User $actor): Subscription
    {
        return DB::transaction(function () use ($item, $pet, $actor) {
            $locked = LegacyPendingItem::lockForUpdate()->findOrFail($item->id);
            if ($locked->resolved_at) {
                throw new DomainException('ALREADY_RESOLVED', 'Este item já foi resolvido.', 409);
            }
            if ($pet->customer_id !== $locked->customer_id || ! $pet->active) {
                throw new DomainException('PET_OWNER_MISMATCH', 'Escolha um pet ativo deste cliente.', 422);
            }
            $subscription = $this->contract($locked->customer, $pet, $locked->productVersion, ['vindi_subscription_id' => $locked->vindi_subscription_id,
                'vindi_product_item_id' => $locked->vindi_product_item_id, 'price_cents' => $locked->price_cents, 'started_at' => $locked->started_at?->toDateString()],
                $actor, Franchise::where('code', 'CENTRAL')->firstOrFail());
            $locked->update(['subscription_id' => $subscription->id, 'resolution' => 'linked', 'resolved_at' => now(), 'resolved_by' => $actor->id]);
            return $subscription;
        }, 3);
    }

    /** Item que não vai virar contrato (pet falecido, cobrança que não é de pet): sai da lista com o motivo registrado. */
    public function dismiss(LegacyPendingItem $item, User $actor, string $reason): void
    {
        DB::transaction(function () use ($item, $actor, $reason) {
            $locked = LegacyPendingItem::lockForUpdate()->findOrFail($item->id);
            if ($locked->resolved_at) {
                throw new DomainException('ALREADY_RESOLVED', 'Este item já foi resolvido.', 409);
            }
            $locked->update(['resolution' => 'dismissed', 'resolved_at' => now(), 'resolved_by' => $actor->id]);
            Audit::record('legacy_item_dismissed', $locked, $actor, ['vindi_product_item_id' => $locked->vindi_product_item_id], $reason, Franchise::where('code', 'CENTRAL')->value('id'));
        }, 3);
    }

    private function contract(Customer $customer, Pet $pet, ProductVersion $version, array $item, User $actor, Franchise $unit): Subscription
    {
        $category = $version->product->category;
        $slot = PetProductSlot::where('pet_id', $pet->id)->where('product_category', $category)->lockForUpdate()->first();
        if ($slot && $slot->state !== 'released') {
            throw new DomainException('PET_CATEGORY_OCCUPIED', 'Este pet já tem um plano desta categoria.');
        }
        $start = $this->start($item);
        $snapshot = ['product' => $version->product->only(['id', 'code', 'name', 'category']),
            'version' => $version->only(['id', 'version', 'name', 'price_cents', 'enrollment_fee_cents', 'billing_cycle', 'duration_months', 'renewal_policy', 'species', 'min_age_months', 'max_age_months', 'allowed_franchise_ids', 'contract_template', 'contract_layout', 'is_demo']),
            'customer' => $customer->only(['id', 'name', 'document', 'email', 'phone', 'address', 'rg']),
            'pet' => $pet->only(['id', 'name', 'species', 'breed', 'color', 'sex', 'birth_date', 'estimated_age_months']),
            'benefits' => [], 'category' => $category, 'price_cents' => (int) $item['price_cents'],
            'legacy' => ['vindi_subscription_id' => (string) $item['vindi_subscription_id'], 'vindi_product_item_id' => (string) $item['vindi_product_item_id'], 'started_at' => $start->toDateString()]];
        $number = 'BA-'.$item['vindi_product_item_id'];
        $proposal = Proposal::create(['number' => $number, 'customer_id' => $customer->id, 'pet_id' => $pet->id, 'product_version_id' => $version->id,
            'originating_franchise_id' => $unit->id, 'created_by' => $actor->id, 'category' => $category, 'channel' => 'legacy', 'prefill_by' => 'seller',
            'signature_method' => 'paper', 'status' => 'paper_issued', 'price_cents' => (int) $item['price_cents'], 'snapshot' => $snapshot,
            'expires_at' => $start, 'locked_at' => $start, 'requested_start_at' => $start]);
        $subscription = Subscription::create(['number' => $number, 'proposal_id' => $proposal->id, 'customer_id' => $customer->id, 'pet_id' => $pet->id,
            'product_version_id' => $version->id, 'originating_franchise_id' => $unit->id, 'category' => $category, 'status' => 'active', 'document_status' => 'pending',
            'locked_at' => $start, 'contract_snapshot' => $snapshot, 'price_cents' => (int) $item['price_cents'], 'requested_start_at' => $start,
            'effective_start_at' => $start, 'activated_at' => $start, 'vindi_subscription_id' => (string) $item['vindi_subscription_id'],
            'vindi_product_item_id' => (string) $item['vindi_product_item_id'], 'provider_account' => (string) config('integrations.vindi.provider_account', 'central'),
            'imported_at' => now()]);
        $values = ['proposal_id' => $proposal->id, 'subscription_id' => $subscription->id, 'state' => 'occupied', 'reserved_at' => $start, 'expires_at' => null, 'released_at' => null];
        $slot ? $slot->update($values) : PetProductSlot::create($values + ['pet_id' => $pet->id, 'product_category' => $category]);
        Audit::record('legacy_contract_imported', $subscription, $actor, ['vindi_subscription_id' => $subscription->vindi_subscription_id, 'vindi_product_item_id' => $subscription->vindi_product_item_id]);
        return $subscription;
    }

    private function start(array $item): CarbonImmutable
    {
        try {
            $start = filled($item['started_at'] ?? null) ? CarbonImmutable::parse($item['started_at'], 'America/Sao_Paulo')->startOfDay() : null;
        } catch (\Throwable) {
            $start = null;
        }
        // Sem data ou com data futura (erro de digitação): vale o dia da importação.
        return (! $start || $start->isFuture() ? CarbonImmutable::now('America/Sao_Paulo')->startOfDay() : $start)->utc();
    }

    private function version(array $plan, array &$report): ProductVersion
    {
        return $this->versions[$plan['name']] ??= DB::transaction(function () use ($plan, &$report) {
            $product = Product::firstOrCreate(['code' => 'BA-'.Str::upper(Str::slug($plan['name']))], ['name' => $plan['name'].' (base antiga)',
                'category' => ($plan['category'] ?? 'health_plan') === 'package' ? 'package' : 'health_plan',
                'description' => 'Plano contratado antes deste sistema. Não é vendido; as condições estão no contrato em papel.', 'active' => false]);
            $version = $product->versions()->where('status', 'legacy')->first();
            if (! $version) {
                $report['planos_base_antiga']++;
                $version = $product->versions()->create(['version' => (int) $product->versions()->max('version') + 1, 'name' => $plan['name'],
                    'price_cents' => (int) $plan['price_cents'], 'billing_cycle' => 'monthly', 'renewal_policy' => 'none', 'species' => ['dog', 'cat'],
                    'contract_template' => 'Plano da base antiga, contratado antes deste sistema. As condições são as do contrato assinado em papel, anexado ao contrato do pet quando digitalizado.',
                    'status' => 'legacy', 'is_demo' => false]);
            }
            return $version->load('product');
        });
    }

    /** Acha pelo cliente da Vindi, depois pelo CPF; só completa campos vazios, sem avisar a Vindi (updateQuietly). */
    private function customer(array $row, Franchise $unit, array &$report): Customer
    {
        $cpf = preg_match('/^\d{11}$/D', (string) ($row['cpf'] ?? '')) ? $row['cpf'] : null;
        $customer = Customer::where('vindi_customer_id', (string) $row['vindi_customer_id'])->first() ?? ($cpf ? Customer::where('document', $cpf)->first() : null);
        $address = array_filter((array) ($row['address'] ?? []), fn ($value) => filled($value));
        $fields = array_filter(['email' => filter_var($row['email'] ?? null, FILTER_VALIDATE_EMAIL) ? mb_strtolower($row['email']) : null,
            'phone' => $row['phone'] ?? null, 'rg' => $row['rg'] ?? null, 'sex' => in_array($row['sex'] ?? null, ['male', 'female'], true) ? $row['sex'] : null,
            'simplesvet_id' => $row['simplesvet_id'] ?? null, 'address' => $address ?: null], fn ($value) => filled($value));
        if (! $customer) {
            $report['clientes_criados']++;
            $name = trim(preg_replace('/\s+/', ' ', preg_replace('/\([^)]*\)/', '', (string) ($row['name_vindi'] ?: ($row['name_sv'] ?? '')))));
            return Customer::create($fields + ['name' => $name ?: 'Cliente da Vindi '.$row['vindi_customer_id'],
                'document' => $cpf && ! Customer::where('document', $cpf)->exists() ? $cpf : null, 'vindi_customer_id' => (string) $row['vindi_customer_id'],
                'originating_franchise_id' => $unit->id, 'active' => true]);
        }
        $missing = array_filter($fields, fn ($value, $key) => blank($customer->{$key}), ARRAY_FILTER_USE_BOTH);
        if (! $customer->vindi_customer_id && ! Customer::where('vindi_customer_id', (string) $row['vindi_customer_id'])->exists()) {
            $missing['vindi_customer_id'] = (string) $row['vindi_customer_id'];
        }
        if ($missing) {
            $customer->forceFill($missing)->saveQuietly();
            $report['clientes_completados']++;
        } else {
            $report['clientes_ja_existentes']++;
        }
        return $customer;
    }

    private function pet(Customer $customer, array $row, array &$report): Pet
    {
        $pets = Pet::where('customer_id', $customer->id)->get();
        $pet = $pets->firstWhere('simplesvet_id', (string) $row['simplesvet_id'])
            ?? $pets->first(fn ($p) => ! $p->simplesvet_id && Str::lower(Str::ascii(trim($p->name))) === Str::lower(Str::ascii(trim((string) $row['name']))));
        $fields = array_filter(['breed' => $row['breed'] ?? null, 'color' => $row['color'] ?? null, 'birth_date' => $row['birth_date'] ?? null,
            'neutered' => $row['neutered'] ?? null, 'simplesvet_id' => (string) $row['simplesvet_id']], fn ($value) => $value !== null && $value !== '');
        if ($pet) {
            $missing = array_filter($fields, fn ($value, $key) => blank($pet->{$key}), ARRAY_FILTER_USE_BOTH);
            if ($missing) {
                $pet->forceFill($missing)->saveQuietly();
            }
            $report['pets_ja_existentes']++;
            return $pet;
        }
        $report['pets_criados']++;
        return Pet::create($fields + ['customer_id' => $customer->id, 'name' => trim((string) $row['name']), 'species' => $row['species'],
            'sex' => in_array($row['sex'] ?? null, ['male', 'female'], true) ? $row['sex'] : 'unknown', 'active' => true]);
    }
}

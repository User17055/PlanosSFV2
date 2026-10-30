<?php

namespace App\Services\Domain;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Invoice;
use App\Models\MarketingLink;
use App\Models\Pet;
use App\Models\PetProductSlot;
use App\Models\ProductVersion;
use App\Models\Proposal;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Attribution\AttributionData;
use App\Services\Attribution\MarketingLinkService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProposalService
{
    public function __construct(private EligibilityService $eligibility) {}

    public function create(array $data, User $actor, ?AttributionData $attribution = null): Proposal
    {
        if (! config('plans.demo', false) && ! config('plans.commercial_approved', false)) {
            throw new DomainException('COMMERCIAL_APPROVAL_PENDING', 'As condições comerciais precisam ser aprovadas antes de novas contratações.', 422);
        }
        $pet = Pet::with('customer')->findOrFail($data['pet_id']);
        $version = ProductVersion::with(['product', 'benefitRules'])->findOrFail($data['product_version_id']);
        $attributionValues = $attribution?->proposalAttributes() ?? [];
        $attributedLink = null;
        if (! empty($attributionValues['marketing_link_id'])) {
            $attributedLink = MarketingLink::findOrFail($attributionValues['marketing_link_id']);
            if (! app(MarketingLinkService::class)->isUsable($attributedLink)
                || $attribution?->originatingFranchiseId !== $attributedLink->originating_franchise_id
                || ($attributionValues['link_owner_id'] ?? null) !== $attributedLink->owner_id) {
                throw new DomainException('ATTRIBUTION_LINK_INVALID', 'A origem compartilhada não está mais disponível. Acesse novamente a contratação.', 409);
            }
        }
        if ($attribution !== null && $actor->role === 'customer') {
            $data['originating_franchise_id'] = $attributedLink?->originating_franchise_id
                ?? Franchise::where('code', 'CENTRAL')->where('active', true)->firstOrFail()->id;
        }
        $franchise = Franchise::findOrFail($data['originating_franchise_id']);
        $channel = $data['channel'] ?? 'link';
        if (! $actor->active || ! $franchise->active) {
            throw new DomainException('FORBIDDEN', 'Usuário ou unidade inativo.', 403);
        }
        if ($pet->customer_id !== (int) $data['customer_id']) {
            throw new DomainException('PET_OWNER_MISMATCH', 'Pet não pertence ao tutor indicado.', 422);
        }
        if ($actor->role === 'customer') {
            if ($actor->customer_id !== $pet->customer_id || $channel !== 'site') {
                throw new DomainException('FORBIDDEN', 'Contratação não permitida.', 403);
            }
        } elseif (! in_array($actor->role, ['central_admin', 'manager', 'sales'], true) || ! $actor->hasFranchise($franchise->id)) {
            throw new DomainException('FORBIDDEN', 'Seu perfil não pode criar propostas nesta unidade.', 403);
        }
        if (! in_array($channel, ['site', 'link', 'presential'], true)) {
            throw new DomainException('CHANNEL_INVALID', 'Canal de contratação inválido.', 422);
        }
        if (!$version->isOffered()) {
            throw new DomainException('PRODUCT_UNAVAILABLE', 'Versão indisponível para novas contratações.', 422);
        }
        if ($version->is_demo && app()->environment('production')) {
            throw new DomainException('DEMO_PRODUCT_DISABLED', 'Produtos ilustrativos não podem ser comercializados em produção.', 422);
        }
        $issues = $this->eligibility->petEligibility($pet, $version);
        if ($issues) {
            throw new DomainException($issues[0]['code'], $issues[0]['message'], 422, ['impediments' => $issues]);
        }
        if (! in_array($version->billing_cycle, ['monthly', 'annual', 'one_time'], true)) {
            throw new DomainException('BILLING_UNSUPPORTED', 'Modalidade de cobrança não habilitada.', 422);
        }
        if ($version->product->category === 'package' && ($version->billing_cycle !== 'one_time' || ! $version->duration_months)) {
            throw new DomainException('PACKAGE_POLICY_PENDING', 'Pacotes exigem validade definida e cobrança única enquanto a política de dívida estiver pendente.', 422);
        }
        $method = $data['signature_method'] ?? ($channel === 'presential' ? 'tablet' : 'digital');
        if (! in_array($method, ['digital', 'tablet', 'paper'], true) || ($channel !== 'presential' && $method !== 'digital')) {
            throw new DomainException('SIGNATURE_METHOD_INVALID', 'Método de assinatura incompatível com o canal.', 422);
        }

        if ($attribution !== null) {
            $attributionValues['attribution_snapshot'] = array_merge($attributionValues['attribution_snapshot'] ?? [], [
                'hiring_channel' => $channel, 'proposal_created_by' => $actor->id, 'proposal_creator_name' => $actor->name,
                'seller_id' => $actor->role === 'customer' ? null : $actor->id, 'originating_franchise_id' => $franchise->id,
                'originating_franchise_name' => $franchise->name, 'marketing_franchise_id' => $attribution->originatingFranchiseId]);
        }

        return DB::transaction(function () use ($data, $actor, $pet, $version, $franchise, $channel, $method, $attributionValues) {
            Customer::lockForUpdate()->findOrFail($pet->customer_id);
            Pet::lockForUpdate()->findOrFail($pet->id);
            $proposal = Proposal::create(['number' => 'PR-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'customer_id' => $pet->customer_id, 'pet_id' => $pet->id, 'product_version_id' => $version->id,
                'originating_franchise_id' => $franchise->id, 'created_by' => $actor->id, 'category' => $version->product->category,
                'channel' => $channel, 'prefill_by' => $data['prefill_by'] ?? 'seller', 'signature_method' => $method,
                'status' => 'awaiting_acceptance', 'price_cents' => $version->price_cents, 'snapshot' => $this->snapshot($pet, $version),
                'requested_start_at' => $data['requested_start_at'] ?? null, 'expires_at' => now()->addHours((int) config('plans.proposal_validity_hours', 48))] + $attributionValues);
            Audit::record('proposal_created', $proposal, $actor, ['channel' => $channel]);

            return $proposal;
        }, 3);
    }

    public function issueLink(Proposal $proposal): string
    {
        if ($proposal->locked_at || $proposal->expires_at->isPast() || !in_array($proposal->status,['draft','awaiting_acceptance'],true)) {
            throw new DomainException('PROPOSAL_NOT_EDITABLE', 'Esta proposta já foi formalizada ou expirou.');
        }
        $token = Str::random(64);
        $proposal->update(['link_token_hash' => hash('sha256', $token), 'link_expires_at' => $proposal->expires_at, 'link_revoked_at' => null]);

        return $token;
    }

    /** Refresh only uncommitted display data before presenting a new review page. */
    public function refreshForReview(Proposal $proposal): Proposal
    {
        return DB::transaction(function () use ($proposal) {
            Customer::lockForUpdate()->findOrFail($proposal->customer_id);
            $pet = Pet::lockForUpdate()->findOrFail($proposal->pet_id);
            $p = Proposal::lockForUpdate()->findOrFail($proposal->id);
            if (! $p->locked_at) {
                $p->update(['snapshot' => $this->snapshot($pet, $p->productVersion()->with(['product', 'benefitRules'])->firstOrFail())]);
            }

            return $p->fresh();
        }, 3);
    }

    public function reviewFingerprint(Proposal $proposal): string
    {
        return $this->snapshotFingerprint($proposal->snapshot);
    }

    public function accept(Proposal $proposal, User $actor, array $evidence = []): Subscription
    {
        if (($evidence['accepted'] ?? false) !== true) {
            throw new DomainException('ACCEPTANCE_REQUIRED', 'O cliente deve manifestar aceite explícito.', 422);
        }
        if (! $actor->active) {
            throw new DomainException('FORBIDDEN', 'Usuário inativo.', 403);
        }
        $customerOwns = $actor->role === 'customer' && $actor->customer_id === $proposal->customer_id && $proposal->signature_method === 'digital';
        $tablet = $proposal->channel === 'presential' && $proposal->signature_method === 'tablet'
            && in_array($actor->role, ['central_admin', 'manager', 'sales'], true) && $actor->hasFranchise($proposal->originating_franchise_id)
            && ($evidence['customer_acceptance'] ?? false) === true && ! empty($evidence['signature']);
        if (! $customerOwns && ! $tablet) {
            throw new DomainException('CUSTOMER_ACCEPTANCE_REQUIRED', 'O aceite pertence ao cliente. O preenchimento pelo vendedor não substitui a assinatura.', 403);
        }
        return $this->commit($proposal, $actor, $evidence, false);
    }

    public function issuePaper(Proposal $proposal, User $actor, array $evidence = []): Subscription
    {
        if (! config('plans.demo', false) && ! config('plans.paper_enabled', false)) {
            throw new DomainException('PAPER_POLICY_PENDING', 'O fluxo de contratação em papel aguarda configuração e aprovação.', 422);
        }
        if ($proposal->channel !== 'presential' || $proposal->signature_method !== 'paper') {
            throw new DomainException('PAPER_CHANNEL_INVALID', 'Emissão em papel exige contratação presencial.', 422);
        }
        if (! $actor->active || ! in_array($actor->role, ['central_admin', 'manager', 'sales'], true) || ! $actor->hasFranchise($proposal->originating_franchise_id)) {
            throw new DomainException('FORBIDDEN', 'Emissão de contrato não permitida.', 403);
        }

        return $this->commit($proposal, $actor, $evidence, true);
    }

    private function commit(Proposal $proposal, User $actor, array $evidence, bool $paper): Subscription
    {
        return DB::transaction(function () use ($proposal, $actor, $evidence, $paper) {
            Customer::lockForUpdate()->findOrFail($proposal->customer_id);
            $pet = Pet::lockForUpdate()->findOrFail($proposal->pet_id);
            $p = Proposal::lockForUpdate()->findOrFail($proposal->id);
            if ($existing = $p->subscription()->first()) {
                return $existing;
            }
            if ($p->locked_at || $p->expires_at->isPast() || ! in_array($p->status, ['draft', 'awaiting_acceptance'], true)) {
                throw new DomainException('PROPOSAL_EXPIRED', 'Proposta expirada ou indisponível para formalização.');
            }
            $version = $p->productVersion()->with(['product', 'benefitRules'])->firstOrFail();
            if (!$version->isOffered()) {
                throw new DomainException('PRODUCT_UNAVAILABLE', 'As condições desta proposta não estão mais disponíveis. Solicite uma nova proposta.', 409);
            }
            if ($pet->customer_id !== $p->customer_id) {
                throw new DomainException('PET_OWNER_MISMATCH', 'A responsabilidade do pet mudou; revise a proposta.', 422);
            }
            $issues = $this->eligibility->petEligibility($pet, $version);
            if ($issues) {
                throw new DomainException($issues[0]['code'], $issues[0]['message'], 422, ['impediments' => $issues]);
            }
            $slot = PetProductSlot::where('pet_id', $pet->id)->where('product_category', $p->category)->lockForUpdate()->first();
            if ($slot && $slot->state !== 'released' && $slot->proposal_id !== $p->id) {
                throw new DomainException('PET_CATEGORY_OCCUPIED', 'Este pet já possui contratação ou reserva desta categoria. Encerre o vínculo anterior para contratar novamente.');
            }
            $snapshot = $this->snapshot($pet, $version);
            $currentFingerprint = $this->snapshotFingerprint($snapshot);
            $reviewedFingerprint = $this->snapshotFingerprint($p->snapshot);
            if (! hash_equals($currentFingerprint, $reviewedFingerprint) || (isset($evidence['review_fingerprint']) && ! hash_equals($reviewedFingerprint, (string) $evidence['review_fingerprint']))) {
                throw new DomainException('PROPOSAL_REVIEW_REQUIRED', 'Os dados da proposta mudaram. Abra a revisão novamente antes de assinar.', 409);
            }
            $acceptedAt=$paper?null:now();
            $evidence['accepted_at']=$acceptedAt?->toIso8601String();
            $evidence['signature_method']=$p->signature_method;
            $evidence['signer_name']=data_get($snapshot,'customer.name');
            $p->update(['snapshot' => $snapshot, 'locked_at' => now(), 'accepted_at' => $acceptedAt,
                'status' => $paper ? 'paper_issued' : 'accepted', 'acceptance_evidence' => $evidence, 'link_revoked_at' => now()]);
            $s = Subscription::create(['number' => 'SF-'.now()->format('Y').'-'.Str::upper(Str::random(8)),
                'proposal_id' => $p->id, 'customer_id' => $p->customer_id, 'pet_id' => $p->pet_id, 'product_version_id' => $p->product_version_id,
                'originating_franchise_id' => $p->originating_franchise_id, 'category' => $p->category, 'status' => 'awaiting_regularization',
                'document_status' => 'pending', 'locked_at' => $p->locked_at, 'contract_snapshot' => $snapshot, 'price_cents' => $p->price_cents,
                'requested_start_at' => $p->requested_start_at] + $p->only(AttributionData::FIELDS));
            $values = ['proposal_id' => $p->id, 'subscription_id' => $s->id, 'state' => 'reserved', 'reserved_at' => now(), 'expires_at' => null, 'released_at' => null];
            if ($slot) {
                $slot->update($values);
            } else {
                PetProductSlot::create($values + ['pet_id' => $p->pet_id, 'product_category' => $p->category]);
            }
            Invoice::create(['subscription_id' => $s->id, 'customer_id' => $s->customer_id, 'originating_franchise_id' => $s->originating_franchise_id,
                'due_at' => now('America/Sao_Paulo')->addDay()->endOfDay()->utc(), 'amount_cents' => $s->price_cents + $version->enrollment_fee_cents,
                'is_initial' => true, 'status' => 'pending', 'reconciliation_status' => 'pending']);
            Audit::record($paper ? 'paper_contract_issued' : 'customer_accepted', $s, $actor, ['proposal_id' => $p->id, 'category' => $p->category]);

            return $s;
        }, 3);
    }

    private function snapshot(Pet $pet, ProductVersion $version): array
    {
        return ['product' => $version->product->only(['id', 'code', 'name', 'category']),
            'version' => $version->only(['id', 'version', 'name', 'price_cents', 'enrollment_fee_cents', 'billing_cycle', 'duration_months', 'renewal_policy', 'species', 'min_age_months', 'max_age_months', 'allowed_franchise_ids', 'contract_template', 'contract_layout', 'is_demo']),
            'customer' => $pet->customer->only(['id', 'name', 'document', 'email', 'phone', 'address', 'rg']),
            'pet' => $pet->only(['id', 'name', 'species', 'breed', 'color', 'sex', 'birth_date', 'estimated_age_months']),
            'benefits' => $version->benefitRules->map->toArray()->all(), 'category' => $version->product->category, 'price_cents' => $version->price_cents];
    }

    private function snapshotFingerprint(array $snapshot): string
    {
        // Normalize Carbon/date values to the same JSON representation as persisted snapshots.
        return Fingerprint::sha256($snapshot);
    }
}

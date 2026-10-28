<?php

namespace App\Services\Domain;

use App\Models\BenefitRule;
use App\Models\BenefitUsage;
use App\Models\Pet;
use App\Models\PetProductSlot;
use App\Models\ProductVersion;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

class EligibilityService
{
    public function __construct(private FinancialService $financial, private PeriodCalculator $periods) {}

    public function petEligibility(Pet $pet, ProductVersion $version): array
    {
        $issues = [];
        if (! $pet->active || ! $pet->customer->active || ! $pet->customer->name || ! $pet->customer->document || ! $pet->name) {
            $issues[] = $this->issue('REGISTRATION_INCOMPLETE', 'Cadastro do tutor ou pet incompleto.');
        }
        if (! in_array($pet->species, $version->species ?? [], true)) {
            $issues[] = $this->issue('SPECIES_NOT_COVERED', 'Espécie não contemplada neste produto.');
        }
        $age = $pet->birth_date ? (int) $pet->birth_date->diffInMonths(now(), false) : $pet->estimated_age_months;
        if ($age === null || $age < 0 || ($version->min_age_months !== null && $age < $version->min_age_months) || ($version->max_age_months !== null && $age > $version->max_age_months)) {
            $issues[] = $this->issue('PET_AGE_INELIGIBLE', 'Idade do pet não elegível para este produto.');
        }

        return $issues;
    }

    public function activationImpediments(Subscription $s): array
    {
        $s->loadMissing(['customer', 'pet.customer', 'productVersion', 'proposal']);
        $issues = [];
        if ($s->productVersion->status==='local_preview' && !ProductVersion::localPreviewEnabled()) {
            $issues[]=$this->issue('LOCAL_PREVIEW_ONLY','Este vínculo pertence ao ambiente local de testes.');
        }
        if ($s->isTerminal()) {
            $issues[] = $this->issue('SUBSCRIPTION_ENDED', 'Contrato cancelado ou encerrado.');
        }
        $issues = array_merge($issues, $this->petEligibility($s->pet, $s->productVersion));
        $document = $s->documents()->orderByDesc('version')->first();
        $valid = $s->document_status === 'valid' && $document && $document->status === 'valid' && $document->kind === 'final_contract'
            && $document->proposal_id === $s->proposal_id && $document->signature_method === $s->proposal->signature_method && $document->validated_at && $document->accepted_at;
        if ($valid) {
            $valid = app(\App\Services\Contracts\ContractService::class)->verifyIntegrity($document);
        }
        if (! $valid) {
            $issues[] = $this->issue('CONTRACT_PENDING', 'Contrato final válido pendente.');
        }
        $initial = $s->invoices()->where('is_initial', true)->get()->filter(fn ($i) => $i->status === 'paid' && $i->paid_cents >= $i->amount_cents && $i->confirmed_at && $i->reconciliation_status === 'verified');
        if ($initial->isEmpty()) {
            $issues[] = $this->issue('FIRST_PAYMENT_PENDING', 'Primeiro pagamento integral ainda não confirmado.');
        }
        if (! PetProductSlot::where('pet_id', $s->pet_id)->where('product_category', $s->category)->where('subscription_id', $s->id)->whereIn('state', ['reserved', 'occupied'])->exists()) {
            $issues[] = $this->issue('SLOT_NOT_OWNED', 'Vaga da categoria não assegurada para esta contratação.');
        }
        if ($s->requested_start_at?->isFuture()) {
            $issues[] = $this->issue('FUTURE_START', 'A vigência contratada ainda não começou.');
        }
        if ($s->ends_at && $s->ends_at->lte(now())) {
            $issues[] = $this->issue('SUBSCRIPTION_EXPIRED', 'A vigência contratada terminou.');
        }
        $financial = $this->financial->customerStatus($s->customer);
        if ($financial['status'] === 'blocked') {
            $issues[] = $this->issue('CUSTOMER_OVERDUE', 'Cliente com mensalidade vencida. Serviços e benefícios bloqueados até a regularização.');
        } elseif ($financial['status'] === 'pending') {
            $issues[] = $this->issue('FINANCIAL_VERIFICATION_PENDING', 'Verificação financeira pendente.');
        }

        return $issues;
    }

    public function subscription(Subscription $s): array
    {
        $issues = $this->activationImpediments($s);
        if (! $s->activated_at || ! in_array($s->status, ['active', 'scheduled_cancellation'], true)) {
            $issues[] = $this->issue('SUBSCRIPTION_NOT_ACTIVE', 'Assinatura ainda não está ativa para atendimento.');
        }

        return $this->result($issues);
    }

    public function benefit(Subscription $s, BenefitRule $rule, ?int $franchiseId = null, int $quantity = 1, ?int $excludeUsageId = null): array
    {
        $result = $this->subscription($s);
        $issues = $result['impediments'];
        $remaining = null;
        $available = null;
        $start = null;
        $end = null;
        if ($rule->product_version_id !== $s->product_version_id) {
            $issues[] = $this->issue('BENEFIT_NOT_COVERED', 'Benefício não pertence ao produto contratado.');
        }
        if ($quantity < 1) {
            $issues[] = $this->issue('QUANTITY_INVALID', 'Quantidade deve ser positiva.');
        }
        $restricted = false;
        foreach ([$s->productVersion->allowed_franchise_ids, $rule->allowed_franchise_ids] as $allowed) {
            if ($franchiseId !== null && is_array($allowed) && ! in_array($franchiseId, $allowed, true)) {
                $restricted = true;
            }
        }
        if ($restricted) {
            $issues[] = $this->issue('FRANCHISE_NOT_COVERED', 'Este benefício não está coberto nesta unidade.');
        }
        if ($rule->copay_cents > 0 || $rule->requires_authorization) {
            $issues[] = $this->issue('ADDITIONAL_APPROVAL_REQUIRED', 'Benefício depende de autorização ou coparticipação ainda não confirmada.');
        }
        if ($rule->coverage_type !== 'included') {
            $issues[] = $this->issue('COVERAGE_FLOW_UNAVAILABLE', 'Fluxo de desconto depende de configuração comercial.');
        }
        if ($s->effective_start_at) {
            $available = CarbonImmutable::parse($s->effective_start_at)->addDays($rule->waiting_days);
            if ($available->isFuture()) {
                $issues[] = $this->issue('BENEFIT_WAITING_PERIOD', 'Carência até '.$available->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i').'.');
            }
            [$start,$end] = $this->periods->calculate($s, $rule);
            $usages = BenefitUsage::where('subscription_id', $s->id)->where('benefit_rule_id', $rule->id)->where('period_start', $start)->when($excludeUsageId, fn ($q) => $q->where('id', '<>', $excludeUsageId))->get();
            $spent = $usages->where('status', 'consumed')->sum('quantity') - $usages->where('status', 'reversal')->sum('quantity');
            $reserved = $usages->where('status', 'reserved')->filter(fn ($u) => $u->expires_at?->gt(now()))->sum('quantity');
            if ($rule->quantity_limit !== null) {
                $remaining = max(0, $rule->quantity_limit - $spent - $reserved);
                if ($quantity > $remaining) {
                    $issues[] = $this->issue('BENEFIT_LIMIT_REACHED', 'Limite de utilização do período atingido.');
                }
            }
        }

        return array_merge($this->result($issues), ['available_from' => $available?->utc()->toIso8601String(), 'remaining' => $remaining, 'period_start' => $start?->toIso8601String(), 'period_end' => $end?->toIso8601String()]);
    }

    private function issue(string $code, string $message): array
    {
        return compact('code', 'message');
    }

    private function result(array $issues): array
    {
        return ['eligible' => count($issues) === 0, 'code' => $issues[0]['code'] ?? 'ELIGIBLE', 'message' => $issues[0]['message'] ?? 'Benefício disponível.', 'impediments' => $issues];
    }
}

<?php

namespace App\Services\Domain;

use App\Models\Commission;
use App\Models\CommissionRate;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Proposal;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccessService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Comissão sobre o primeiro pagamento confirmado de cada assinatura.
 *
 * Quem recebe: a pessoa da equipe que fez a proposta; se o próprio tutor contratou, o dono do link de
 * divulgação pelo qual ele chegou. Sem uma pessoa da equipe identificada, a contratação continua
 * registrada para análise de origem, mas não cria valor a pagar automaticamente.
 * A comissão nasce quando a primeira fatura é quitada e conciliada, e é estornada se esse pagamento
 * for estornado ou cancelado. Percentuais têm histórico; o valor calculado nunca muda depois.
 */
class CommissionService
{
    public function __construct(private AccessService $access) {}

    /** Chamado por FinancialService::applyVerifiedInvoice, dentro da mesma transação, a cada mudança de fatura. */
    public function syncForInvoice(Invoice $invoice): ?Commission
    {
        if (! $invoice->is_initial) {
            return null;
        }
        $commission = Commission::where('subscription_id', $invoice->subscription_id)->lockForUpdate()->first();
        $paidInFull = $invoice->status === 'paid' && $invoice->paid_cents >= $invoice->amount_cents
            && $invoice->confirmed_at && $invoice->reconciliation_status === 'verified';

        if (! $commission) {
            return $paidInFull ? $this->create($invoice) : null;
        }
        if (in_array($invoice->status, ['refunded', 'cancelled'], true) && $commission->status !== 'reversed') {
            $clawback = $commission->status === 'paid';
            $commission->update(['status' => 'reversed', 'reversed_at' => now(), 'clawback' => $clawback,
                'reversal_reason' => $invoice->status === 'refunded' ? 'Primeiro pagamento estornado.' : 'Primeira fatura cancelada.']);
            Audit::record('commission_reversed', $commission, null, ['invoice_status' => $invoice->status, 'clawback' => $clawback]);
        } elseif ($invoice->status === 'disputed' && in_array($commission->status, ['payable', 'awaiting_rate'], true)) {
            $commission->update(['status' => 'held']);
            Audit::record('commission_held', $commission, null, ['invoice_status' => 'disputed']);
        } elseif ($paidInFull && $commission->status === 'held') {
            $commission->update(['status' => $commission->rate_basis_points === null ? 'awaiting_rate' : 'payable']);
            Audit::record('commission_released', $commission, null, ['invoice_status' => 'paid']);
        }

        return $commission->fresh();
    }

    /** @return array{0: string, 1: ?User, 2: string} tipo, usuário beneficiário e regra aplicada */
    public function beneficiary(Subscription $subscription, Proposal $proposal): array
    {
        $creator = $proposal->creator;
        if ($creator && $creator->role !== 'customer') {
            return ['seller', $creator, 'seller_proposal'];
        }
        $owner = $subscription->linkOwner;
        if ($owner && $owner->role !== 'customer') {
            return ['link_owner', $owner, 'link_owner'];
        }

        return ['unassigned', null, $subscription->referrer_customer_id || $subscription->referrer_name ? 'customer_referral' : 'site_self_service'];
    }

    public function amount(int $baseCents, int $basisPoints): int
    {
        return intdiv($baseCents * $basisPoints + 5000, 10000);
    }

    public function amountFor(CommissionRate $rate, int $baseCents): int
    {
        return $rate->calculation_type === 'fixed'
            ? (int) $rate->fixed_amount_cents
            : $this->amount($baseCents, (int) $rate->rate_basis_points);
    }

    public function rateFor(int $productId, CarbonInterface $at): ?CommissionRate
    {
        return CommissionRate::where('product_id', $productId)->where('effective_from', '<=', $at)
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    /** Percentual vigente de cada produto, para a tela de configuração. */
    public function currentRates(): Collection
    {
        $rates = CommissionRate::orderByDesc('effective_from')->orderByDesc('id')->get()->unique('product_id')->keyBy('product_id');

        return Product::orderBy('category')->orderBy('name')->get()->map(fn (Product $product) => ['product' => $product, 'rate' => $rates->get($product->id)]);
    }

    /** Novo percentual vale para os próximos primeiros pagamentos e para comissões que aguardavam percentual. */
    public function setRate(Product $product, int $basisPoints, User $actor, ?string $note = null): CommissionRate
    {
        return $this->setRule($product, 'percent', $basisPoints, $actor, $note);
    }

    /** Valor é em pontos-base para percentual e em centavos para valor fixo. */
    public function setRule(Product $product, string $type, int $value, User $actor, ?string $note = null): CommissionRate
    {
        if (! $actor->active || $actor->role !== 'central_admin') {
            throw new DomainException('FORBIDDEN', 'Somente a administração central define percentuais de comissão.', 403);
        }
        if (! in_array($type, ['percent', 'fixed'], true)
            || ($type === 'percent' && ($value < 0 || $value > 10000))
            || ($type === 'fixed' && ($value < 0 || $value > 100000000))) {
            throw new DomainException('COMMISSION_RATE_INVALID', 'Informe uma regra de comissão válida.', 422);
        }

        return DB::transaction(function () use ($product, $type, $value, $actor, $note) {
            $basisPoints = $type === 'percent' ? $value : 0;
            $fixedAmount = $type === 'fixed' ? $value : null;
            $rate = CommissionRate::create(['product_id' => $product->id, 'rate_basis_points' => $basisPoints,
                'calculation_type' => $type, 'fixed_amount_cents' => $fixedAmount, 'effective_from' => now(),
                'is_demo' => false, 'note' => $note, 'created_by' => $actor->id]);
            Audit::record('commission_rate_set', $rate, $actor, ['product_id' => $product->id, 'calculation_type' => $type,
                'rate_basis_points' => $basisPoints, 'fixed_amount_cents' => $fixedAmount], $note, null);
            foreach (Commission::where('product_id', $product->id)->where('status', 'awaiting_rate')->lockForUpdate()->get() as $commission) {
                $commission->update(['commission_rate_id' => $rate->id, 'rate_basis_points' => $basisPoints,
                    'calculation_type' => $type, 'fixed_amount_cents' => $fixedAmount,
                    'amount_cents' => $this->amountFor($rate, $commission->base_cents), 'status' => 'payable']);
                Audit::record('commission_calculated', $commission, $actor, ['calculation_type' => $type,
                    'rate_basis_points' => $basisPoints, 'fixed_amount_cents' => $fixedAmount]);
            }

            return $rate;
        });
    }

    public function markPaid(Commission $commission, User $actor, string $reference): Commission
    {
        if (! $actor->active || ! in_array($actor->role, ['central_admin', 'finance'], true)
            || ($actor->role !== 'central_admin' && ! $actor->hasFranchise($commission->originating_franchise_id))) {
            throw new DomainException('FORBIDDEN', 'Seu perfil não pode registrar o pagamento desta comissão.', 403);
        }
        $reference = trim($reference);
        if (mb_strlen($reference) < 3) {
            throw new DomainException('PAYOUT_REFERENCE_REQUIRED', 'Informe a referência do pagamento ao beneficiário.', 422);
        }

        return DB::transaction(function () use ($commission, $actor, $reference) {
            $current = Commission::lockForUpdate()->findOrFail($commission->id);
            if ($current->status !== 'payable') {
                throw new DomainException('COMMISSION_NOT_PAYABLE', 'Esta comissão não está disponível para pagamento.', 422);
            }
            $current->update(['status' => 'paid', 'paid_at' => now(), 'paid_by' => $actor->id, 'payout_reference' => $reference]);
            Audit::record('commission_paid', $current, $actor, ['amount_cents' => $current->amount_cents], $reference);

            return $current;
        });
    }

    /** Administração central vê tudo; gestão e financeiro, as unidades autorizadas; vendas, só as próprias. */
    public function query(User $user): Builder
    {
        $query = Commission::query();

        return match ($user->role) {
            'central_admin' => $query,
            'manager', 'finance' => $query->whereIn('originating_franchise_id', $this->access->franchiseIds($user)),
            'sales' => $query->where('beneficiary_user_id', $user->id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function create(Invoice $invoice): Commission
    {
        $subscription = Subscription::with(['proposal.creator', 'productVersion.product', 'marketingLink', 'linkOwner'])->findOrFail($invoice->subscription_id);
        $proposal = $subscription->proposal;
        [$type, $user, $rule] = $this->beneficiary($subscription, $proposal);
        $product = $subscription->productVersion->product;
        $earnedAt = $invoice->confirmed_at ?? now();
        $rate = $type === 'unassigned' ? null : $this->rateFor($product->id, $earnedAt);
        $base = (int) $invoice->paid_cents;
        $creator = $proposal->creator;

        $commission = Commission::create([
            'subscription_id' => $subscription->id, 'proposal_id' => $proposal->id, 'invoice_id' => $invoice->id,
            'originating_franchise_id' => $subscription->originating_franchise_id, 'product_id' => $product->id,
            'product_version_id' => $subscription->product_version_id, 'category' => $subscription->category,
            'sold_by_id' => $creator && $creator->role !== 'customer' ? $creator->id : null,
            'beneficiary_type' => $type, 'beneficiary_user_id' => $user?->id,
            'beneficiary_name' => $user?->name ?? 'Sem responsável comissionado',
            'rule' => $rule, 'origin_snapshot' => [
                'channel' => $proposal->channel, 'acquisition_source' => $subscription->acquisition_source ?? 'unknown',
                'campaign_name' => $subscription->campaign_name, 'marketing_link_name' => data_get($subscription->attribution_snapshot, 'marketing_link_name') ?? $subscription->marketingLink?->name,
                'link_owner_name' => $subscription->linkOwner?->name, 'referrer_name' => $subscription->referrer_name,
                'creator_name' => $creator?->name, 'creator_role' => $creator?->role, 'utm_source' => $subscription->utm_source,
                'proposal_number' => $proposal->number,
            ],
            'base_cents' => $base, 'commission_rate_id' => $rate?->id, 'rate_basis_points' => $rate?->rate_basis_points,
            'calculation_type' => $rate?->calculation_type, 'fixed_amount_cents' => $rate?->fixed_amount_cents,
            'amount_cents' => $rate ? $this->amountFor($rate, $base) : 0,
            'status' => $type === 'unassigned' ? 'not_applicable' : ($rate ? 'payable' : 'awaiting_rate'), 'earned_at' => $earnedAt,
            'is_demo' => (bool) $subscription->productVersion->is_demo || str_starts_with((string) $invoice->payment_reference, 'demo'),
        ]);
        Audit::record('commission_earned', $commission, null, ['beneficiary_type' => $type, 'rule' => $rule, 'base_cents' => $base, 'rate_basis_points' => $rate?->rate_basis_points]);

        return $commission;
    }
}

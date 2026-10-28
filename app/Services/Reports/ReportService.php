<?php

namespace App\Services\Reports;

use App\Models\{AttributionTouch, Franchise, MarketingLink, Payment, Product, Proposal, User};
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportService
{
    public const SOURCES = ['unknown' => 'Não informado', 'direct' => 'Acesso direto', 'referral' => 'Indicação', 'shared_link' => 'Link compartilhado', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'google' => 'Google', 'other' => 'Outra origem'];
    public const CHANNELS = ['site' => 'Site', 'link' => 'Link individual', 'presential' => 'Presencial'];
    public const STATUSES = ['no_subscription' => 'Sem assinatura formalizada', 'awaiting_regularization' => 'Aguardando regularização', 'active' => 'Ativa', 'suspended' => 'Suspensa', 'scheduled_cancellation' => 'Cancelamento agendado', 'cancelled' => 'Cancelada', 'ended' => 'Encerrada'];

    public function authorize(User $user): void
    {
        abort_unless($user->active && in_array($user->role, ['central_admin', 'manager', 'finance'], true), 403, 'Seu perfil não pode consultar relatórios comerciais.');
    }

    public function franchiseIds(User $user): array
    {
        $this->authorize($user);
        return DB::table('user_franchises')->join('franchises', 'franchises.id', '=', 'user_franchises.franchise_id')
            ->where('user_franchises.user_id', $user->id)->where('user_franchises.active', true)->where('franchises.active', true)
            ->pluck('franchises.id')->map(fn ($id) => (int) $id)->all();
    }

    public function filters(User $user, array $input): array
    {
        $this->authorize($user);
        $today = CarbonImmutable::now('America/Sao_Paulo');
        $input['date_from'] = $input['date_from'] ?? $today->startOfMonth()->toDateString();
        $input['date_to'] = $input['date_to'] ?? $today->toDateString();
        $data = Validator::make($input, [
            'date_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.$today->toDateString()],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from', 'before_or_equal:'.$today->toDateString()],
            'franchise_id' => ['nullable', 'integer', 'min:1'], 'salesperson_id' => ['nullable', 'integer', 'min:1'],
            'link_owner_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', 'min:1'], 'marketing_link_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', Rule::in(array_keys(self::CHANNELS))],
            'acquisition_source' => ['nullable', Rule::in(array_keys(self::SOURCES))],
            'campaign' => ['nullable', 'string', 'max:180'], 'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
        ], ['date_from.*' => 'Informe uma data inicial válida, a partir de 2000 e até hoje.', 'date_to.*' => 'A data final deve estar entre a inicial e hoje.'])->validate();
        if (CarbonImmutable::parse($data['date_from'])->diffInDays(CarbonImmutable::parse($data['date_to'])) > 365) {
            throw ValidationException::withMessages(['date_to' => 'Selecione um período de até 366 dias.']);
        }
        foreach (['franchise_id', 'salesperson_id', 'link_owner_id', 'product_id', 'marketing_link_id'] as $key) {
            $data[$key] = filled($data[$key] ?? null) ? (int) $data[$key] : null;
        }
        foreach (['channel', 'acquisition_source', 'campaign', 'status'] as $key) {
            $data[$key] = filled($data[$key] ?? null) ? $data[$key] : null;
        }
        if ($data['franchise_id'] && $user->role !== 'central_admin') {
            abort_unless(in_array($data['franchise_id'], $this->franchiseIds($user), true), 403, 'Unidade fora do seu acesso autorizado.');
        }
        return $data;
    }

    public function cohort(User $user, array $filters): Builder
    {
        $this->authorize($user);
        [$from, $until] = $this->bounds($filters);
        $query = $this->scopedProposals($user)->where('proposals.created_at', '>=', $from)->where('proposals.created_at', '<', $until);
        foreach (['franchise_id' => 'originating_franchise_id', 'salesperson_id' => 'created_by', 'link_owner_id' => 'link_owner_id', 'channel' => 'channel', 'acquisition_source' => 'acquisition_source', 'marketing_link_id' => 'marketing_link_id', 'campaign' => 'campaign_name'] as $filter => $column) {
            if (filled($filters[$filter] ?? null)) $query->where('proposals.'.$column, $filters[$filter]);
        }
        if ($filters['product_id'] ?? null) $query->whereHas('productVersion', fn ($q) => $q->where('product_id', $filters['product_id']));
        if (($filters['status'] ?? null) === 'no_subscription') $query->whereDoesntHave('subscription');
        elseif ($filters['status'] ?? null) $query->whereHas('subscription', fn ($q) => $q->where('status', $filters['status']));
        return $query;
    }

    public function report(User $user, array $filters): array
    {
        $base = $this->cohort($user, $filters);
        $payments = $this->payments($base, $filters);
        $summary = [
            'proposals' => (clone $base)->count(),
            'accepted' => $this->accepted(clone $base)->count(),
            'documents_valid' => (clone $base)->whereHas('subscription', fn ($q) => $q->where('document_status', 'valid'))->count(),
            'initial_paid' => (clone $base)->whereHas('subscription.invoices', fn ($q) => $this->initialPaid($q))->count(),
            'activated' => (clone $base)->whereHas('subscription', fn ($q) => $q->whereNotNull('activated_at'))->count(),
            'active_now' => (clone $base)->whereHas('subscription', fn ($q) => $q->whereIn('status', ['active', 'scheduled_cancellation']))->count(),
            'received_cents' => (int) (clone $payments)->where('source', 'not like', 'demo%')->sum('amount_cents'),
            'demo_received_cents' => (int) (clone $payments)->where('source', 'like', 'demo%')->sum('amount_cents'),
        ];
        $proposals = $this->withDetails(clone $base, $filters)->latest('proposals.created_at')->orderByDesc('proposals.id')->paginate(20)->withQueryString();
        $proposals->through(fn (Proposal $proposal) => $this->row($proposal));
        return ['filters' => $filters, 'summary' => $summary, 'proposals' => $proposals,
            'linkReport' => $this->linkReport($user, $filters, $base), 'options' => $this->options($user),
            'generatedAt' => CarbonImmutable::now('America/Sao_Paulo')];
    }

    public function exportCount(User $user, array $filters): int
    {
        $count = $this->cohort($user, $filters)->count();
        if ($count > 10000) throw ValidationException::withMessages(['date_from' => 'A exportação aceita até 10.000 propostas. Reduza o período ou aplique filtros.']);
        return $count;
    }

    public function exportRows(User $user, array $filters): \Generator
    {
        foreach ($this->withDetails($this->cohort($user, $filters), $filters)->reorder()->lazyById(200) as $proposal) {
            yield $this->row($proposal);
        }
    }

    public function csvValues(array $row): array
    {
        $values = [$row['number'], $row['created_at'], $row['customer'], $row['pet'], $row['product'], $row['category'],
            $row['seller'], $row['franchise'], $row['channel'], $row['source'], $row['referrer'], $row['campaign'],
            $row['marketing_link'], $row['link_owner'], $row['proposal_status'], $row['operational_status'],
            $row['document_status'], $row['initial_payment'], $row['accepted_at'], $row['activated_at'],
            number_format($row['received_cents'] / 100, 2, ',', ''), number_format($row['demo_received_cents'] / 100, 2, ',', '')];
        return array_map(fn ($value) => $this->csvSafe($value), $values);
    }

    public function csvSafe(mixed $value): string
    {
        $value = (string) ($value ?? '');
        return preg_match('/^[\x00-\x20\x{FEFF}]*[=+\-@]/u', $value) || preg_match('/^[\t\r\n]/', $value) ? "'".$value : $value;
    }

    private function scopedProposals(User $user): Builder
    {
        $query = Proposal::query();
        return $user->role === 'central_admin' ? $query : $query->whereIn('proposals.originating_franchise_id', $this->franchiseIds($user));
    }

    private function scopedLinks(User $user): Builder
    {
        $query = MarketingLink::query()->select(['id', 'name', 'acquisition_source', 'owner_id', 'originating_franchise_id', 'product_version_id', 'campaign_name', 'status', 'expires_at']);
        return $user->role === 'central_admin' ? $query : $query->whereIn('originating_franchise_id', $this->franchiseIds($user));
    }

    private function accepted(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNotNull('proposals.accepted_at')->orWhereHas('subscription.documents', fn ($doc) => $doc->where('kind', 'final_contract')->where('signature_method', 'paper')->where('status', 'valid')->whereNotNull('accepted_at')->whereNotNull('validated_at'));
        });
    }

    private function initialPaid(Builder $query): Builder
    {
        return $query->where('is_initial', true)->where('status', 'paid')->whereColumn('paid_cents', '>=', 'amount_cents')
            ->where('reconciliation_status', 'verified')->whereNotNull('confirmed_at');
    }

    private function payments(Builder $base, array $filters): Builder
    {
        [$from, $until] = $this->bounds($filters);
        return Payment::query()->where('paid_at', '>=', $from)->where('paid_at', '<', $until)
            ->whereHas('invoice', fn ($invoice) => $invoice->where('reconciliation_status', 'verified')->whereNotNull('confirmed_at')
                ->whereIn('status', ['paid', 'partial'])->whereHas('subscription', fn ($subscription) => $subscription->whereIn('proposal_id', (clone $base)->select('proposals.id'))));
    }

    private function withDetails(Builder $query, array $filters): Builder
    {
        [$from, $until] = $this->bounds($filters);
        return $query->with(['customer:id,name', 'pet:id,name', 'creator:id,name,role', 'originatingFranchise:id,name',
            'productVersion.product:id,name,category', 'marketingLink:id,name', 'linkOwner:id,name', 'referrerCustomer:id,name',
            'subscription.documents' => fn ($q) => $q->orderByDesc('version'),
            'subscription.invoices.payments' => fn ($q) => $q->where('paid_at', '>=', $from)->where('paid_at', '<', $until)]);
    }

    private function row(Proposal $proposal): array
    {
        $s = $proposal->subscription;
        $snapshot = $s?->contract_snapshot ?? $proposal->snapshot ?? [];
        $paperAccepted = $s?->documents->first(fn ($d) => $d->kind === 'final_contract' && $d->signature_method === 'paper' && $d->status === 'valid' && $d->accepted_at && $d->validated_at);
        $accepted = $proposal->accepted_at ?? $paperAccepted?->accepted_at;
        $initial = $s?->invoices->first(fn ($i) => $i->is_initial && $i->status === 'paid' && $i->paid_cents >= $i->amount_cents && $i->reconciliation_status === 'verified' && $i->confirmed_at);
        $received = $demoReceived = 0;
        foreach ($s?->invoices ?? [] as $invoice) {
            if ($invoice->reconciliation_status !== 'verified' || ! $invoice->confirmed_at || ! in_array($invoice->status, ['paid', 'partial'], true)) continue;
            foreach ($invoice->payments as $payment) {
                if (str_starts_with(strtolower((string) $payment->source), 'demo')) $demoReceived += $payment->amount_cents;
                else $received += $payment->amount_cents;
            }
        }
        $source = $proposal->acquisition_source ?? 'unknown';
        return [
            'id' => $proposal->id, 'number' => $proposal->number, 'created_at' => $this->date($proposal->created_at),
            'customer' => data_get($snapshot, 'customer.name') ?? $proposal->customer?->name ?? 'Não informado',
            'pet' => data_get($snapshot, 'pet.name') ?? $proposal->pet?->name ?? 'Não informado',
            'product' => data_get($snapshot, 'product.name') ?? $proposal->productVersion?->product?->name ?? 'Não informado',
            'category' => ['health_plan' => 'Plano de Saúde', 'package' => 'Pacote'][$proposal->category] ?? 'Não informado',
            'seller' => $proposal->creator?->name ?? 'Não informado', 'self_service' => $proposal->creator?->role === 'customer',
            'franchise' => $proposal->originatingFranchise?->name ?? 'Não informado',
            'channel' => self::CHANNELS[$proposal->channel] ?? 'Não informado', 'source' => self::SOURCES[$source] ?? 'Não informado',
            'referrer' => $proposal->referrer_name ?: ($proposal->referrerCustomer?->name ?? 'Não informado'),
            'campaign' => $proposal->campaign_name ?: 'Não informado',
            'marketing_link' => $proposal->marketing_link_id ? '#'.$proposal->marketing_link_id.' · '.($proposal->marketingLink?->name ?? 'Link registrado') : 'Não informado',
            'link_owner' => $proposal->linkOwner?->name ?? 'Não informado',
            'proposal_status' => ['draft' => 'Rascunho', 'awaiting_acceptance' => 'Aguardando aceite', 'accepted' => 'Aceita', 'paper_issued' => 'Papel emitido', 'cancelled' => 'Cancelada', 'expired' => 'Expirada'][$proposal->status] ?? $proposal->status,
            'operational_key' => $s?->status ?? 'no_subscription', 'operational_status' => self::STATUSES[$s?->status ?? 'no_subscription'] ?? 'Não informado',
            'document_status' => ['pending' => 'Pendente', 'valid' => 'Válido', 'rejected' => 'Rejeitado'][$s?->document_status ?? 'pending'] ?? 'Pendente',
            'initial_payment' => $initial ? 'Integral confirmado' : ($s ? 'Não confirmado' : 'Sem cobrança formalizada'),
            'accepted_at' => $this->date($accepted), 'activated_at' => $this->date($s?->activated_at),
            'received_cents' => $received, 'demo_received_cents' => $demoReceived,
        ];
    }

    private function linkReport(User $user, array $filters, Builder $cohort): array
    {
        [$from, $until] = $this->bounds($filters);
        $links = $this->scopedLinks($user);
        foreach (['franchise_id' => 'originating_franchise_id', 'link_owner_id' => 'owner_id', 'acquisition_source' => 'acquisition_source', 'marketing_link_id' => 'id', 'campaign' => 'campaign_name'] as $filter => $column) {
            if (filled($filters[$filter] ?? null)) $links->where($column, $filters[$filter]);
        }
        if ($filters['product_id'] ?? null) $links->whereHas('productVersion', fn ($q) => $q->where('product_id', $filters['product_id']));
        $touches = AttributionTouch::query()->whereIn('marketing_link_id', (clone $links)->select('id'))->where('touched_at', '>=', $from)->where('touched_at', '<', $until);
        if ($user->role !== 'central_admin') $touches->whereIn('originating_franchise_id', $this->franchiseIds($user));
        $accesses = (clone $touches)->count();
        $links->with(['owner:id,name', 'originatingFranchise:id,name'])
            ->withCount(['entryTouches as access_count' => fn ($q) => $q->whereIn('id', (clone $touches)->select('id')),
                'proposals as proposal_count' => fn ($q) => $q->whereIn('proposals.id', (clone $cohort)->select('proposals.id')),
                'proposals as accepted_count' => fn ($q) => $this->accepted($q)->whereIn('proposals.id', (clone $cohort)->select('proposals.id')),
                'proposals as activated_count' => fn ($q) => $q->whereIn('proposals.id', (clone $cohort)->select('proposals.id'))->whereHas('subscription', fn ($s) => $s->whereNotNull('activated_at'))]);
        return ['accesses' => $accesses, 'links' => $links->orderByDesc('access_count')->orderBy('id')->paginate(8, ['*'], 'links_page')->withQueryString()];
    }

    private function options(User $user): array
    {
        $scoped = $this->scopedProposals($user);
        return [
            'franchises' => Franchise::query()->when($user->role !== 'central_admin', fn ($q) => $q->whereIn('id', $this->franchiseIds($user)))->orderBy('name')->get(['id', 'name', 'active']),
            'salespeople' => User::whereIn('id', (clone $scoped)->select('created_by'))->orderBy('name')->get(['id', 'name', 'role']),
            'linkOwners' => User::where(fn ($q) => $q->whereIn('id', $this->scopedLinks($user)->select('owner_id'))
                ->orWhereIn('id', (clone $scoped)->whereNotNull('link_owner_id')->select('link_owner_id')))->orderBy('name')->get(['id', 'name']),
            'products' => Product::whereHas('versions.proposals', fn ($q) => $q->whereIn('proposals.id', (clone $scoped)->select('proposals.id')))->orderBy('name')->get(['id', 'name']),
            'marketingLinks' => $this->scopedLinks($user)->orderBy('name')->get(),
            'campaigns' => (clone $scoped)->whereNotNull('campaign_name')->where('campaign_name', '!=', '')->distinct()->orderBy('campaign_name')->pluck('campaign_name'),
        ];
    }

    private function bounds(array $filters): array
    {
        return [CarbonImmutable::parse($filters['date_from'], 'America/Sao_Paulo')->startOfDay()->utc(),
            CarbonImmutable::parse($filters['date_to'], 'America/Sao_Paulo')->addDay()->startOfDay()->utc()];
    }

    private function date(mixed $value): string
    {
        return $value ? CarbonImmutable::parse($value)->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i') : 'Não informado';
    }
}

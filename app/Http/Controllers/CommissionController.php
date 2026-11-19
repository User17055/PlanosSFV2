<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use App\Models\Franchise;
use App\Models\Product;
use App\Models\Proposal;
use App\Models\User;
use App\Services\AccessService;
use App\Services\Domain\CommissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CommissionController extends Controller
{
    public function index(Request $request, CommissionService $commissions, AccessService $access)
    {
        $user = $request->user();
        $filters = $request->validate([
            'date_from' => 'nullable|date', 'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => 'nullable|in:'.implode(',', array_keys(Commission::STATUSES)),
            'beneficiary' => 'nullable|string|max:20', 'franchise_id' => 'nullable|integer',
        ]);
        $base = $commissions->query($user);
        $timezone = 'America/Sao_Paulo';
        // Sem período escolhido, abre no mês atual: é o fechamento que a equipe confere.
        if (empty($filters['date_from']) && empty($filters['date_to'])) {
            $filters['date_from'] = now($timezone)->startOfMonth()->toDateString();
            $filters['date_to'] = now($timezone)->endOfMonth()->toDateString();
        }
        if (! empty($filters['date_from'])) $base->where('earned_at', '>=', Carbon::parse($filters['date_from'], $timezone)->startOfDay()->utc());
        if (! empty($filters['date_to'])) $base->where('earned_at', '<=', Carbon::parse($filters['date_to'], $timezone)->endOfDay()->utc());
        if (! empty($filters['franchise_id'])) $base->where('originating_franchise_id', (int) $filters['franchise_id']);
        if (($filters['beneficiary'] ?? '') === 'unassigned') $base->where('beneficiary_type', 'unassigned');
        elseif (ctype_digit((string) ($filters['beneficiary'] ?? ''))) $base->where('beneficiary_user_id', (int) $filters['beneficiary']);

        $totals = (clone $base)->selectRaw('status, COUNT(*) as total, COALESCE(SUM(amount_cents),0) as amount')->groupBy('status')->get()->keyBy('status');
        // Por funcionário no período: valores sem as estornadas; a qualidade conta todas as vendas e olha como o plano está hoje.
        $people = (clone $base)->where('status', '!=', 'not_applicable')->whereNotNull('beneficiary_user_id')->with('subscription:id,status')
            ->get(['id', 'subscription_id', 'beneficiary_user_id', 'beneficiary_name', 'amount_cents', 'status'])
            ->groupBy('beneficiary_user_id')->map(function ($sales) {
                $kept = $sales->where('status', '!=', 'reversed');
                $left = $sales->filter(fn ($c) => $c->status === 'reversed' || in_array($c->subscription?->status, ['cancelled', 'ended'], true))->count();
                $late = $kept->where('subscription.status', 'suspended')->count();

                return (object) ['beneficiary_user_id' => $sales->first()->beneficiary_user_id, 'name' => $sales->first()->beneficiary_name,
                    'sold' => $sales->count(), 'total' => $kept->sum('amount_cents'),
                    'payable' => $kept->where('status', 'payable')->sum('amount_cents'), 'paid' => $kept->where('status', 'paid')->sum('amount_cents'),
                    'waiting' => $kept->whereIn('status', ['awaiting_rate', 'held'])->count(), 'left' => $left,
                    'late' => $late, 'on_time' => $sales->count() - $late - $left]; // em dia: nem em atraso nem cancelou
            })->sortByDesc('total')->values();
        $list = clone $base;
        if (! empty($filters['status'])) $list->where('status', $filters['status']);
        $rows = $list->with(['subscription.customer', 'subscription.pet', 'productVersion'])
            ->orderByDesc('earned_at')->orderByDesc('id')->paginate(20)->withQueryString();

        $beneficiaries = $user->role === 'sales' ? collect() : $commissions->query($user)->select('beneficiary_type', 'beneficiary_user_id', 'beneficiary_name')
            ->distinct()->orderBy('beneficiary_name')->get()
            ->unique(fn ($b) => $b->beneficiary_type === 'unassigned' ? 'unassigned' : $b->beneficiary_user_id); // a mesma pessoa como vendedora e como dona do link
        $franchises = $user->role === 'central_admin' ? Franchise::where('active', true)->orderBy('name')->get()
            : Franchise::whereIn('id', $access->franchiseIds($user))->orderBy('name')->get();

        return view('commissions.index', [
            'rows' => $rows, 'totals' => $totals, 'filters' => $filters, 'people' => $people, 'beneficiaries' => $beneficiaries, 'franchises' => $franchises,
            'rates' => $user->role === 'central_admin' ? $commissions->currentRates() : collect(),
            'canPay' => in_array($user->role, ['central_admin', 'finance'], true) && $user->canUse('commissions.pay'),
            'staff' => in_array($user->role, ['central_admin', 'manager'], true) && $user->canUse('proposals.seller')
                ? User::where('active', true)->whereIn('role', ['central_admin', 'manager', 'sales'])->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    public function pay(Request $request, Commission $commission, CommissionService $commissions)
    {
        abort_unless($commissions->query($request->user())->whereKey($commission->id)->exists(), 403, 'Comissão fora do seu escopo de acesso.');
        $data = $request->validate(['payout_reference' => 'required|string|min:3|max:120']);
        $commissions->markPaid($commission, $request->user(), $data['payout_reference']);

        return back()->with('success', 'Pagamento da comissão registrado.');
    }

    public function seller(Request $request, Proposal $proposal, CommissionService $commissions, AccessService $access)
    {
        $access->record($request->user(), $proposal);
        $seller = User::findOrFail($request->validate(['seller_id' => 'required|integer'])['seller_id']);
        $commissions->changeSeller($proposal, $seller, $request->user());

        return back()->with('success', "A venda da proposta {$proposal->number} agora é de {$seller->name}.");
    }

    public function rates(Request $request, CommissionService $commissions)
    {
        $data = $request->validate([
            'calculation_types' => 'nullable|array', 'calculation_types.*' => 'nullable|in:percent,fixed',
            'values' => 'nullable|array', 'values.*' => 'nullable|numeric|min:0|max:1000000',
            'rates' => 'nullable|array', 'rates.*' => 'nullable|numeric|min:0|max:100',
            'note' => 'nullable|string|max:180',
        ]);
        abort_unless(! empty($data['values']) || ! empty($data['rates']), 422, 'Informe ao menos uma regra de comissão.');
        $current = $commissions->currentRates()->keyBy(fn ($row) => $row['product']->id);
        $changed = 0;
        $submitted = $data['values'] ?? $data['rates'] ?? [];
        foreach ($submitted as $productId => $value) {
            if ($value === null || $value === '' || ! $current->has((int) $productId)) continue;
            $type = isset($data['values']) ? ($data['calculation_types'][$productId] ?? 'percent') : 'percent';
            $normalized = $type === 'fixed'
                ? (int) round(((float) $value) * 100)
                : (int) round(((float) $value) * 100);
            $existing = $current[(int) $productId]['rate'];
            if ($existing && ($existing->calculation_type ?? 'percent') === $type
                && ($type === 'fixed' ? (int) $existing->fixed_amount_cents : (int) $existing->rate_basis_points) === $normalized) continue;
            $commissions->setRule(Product::findOrFail((int) $productId), $type, $normalized, $request->user(), $data['note'] ?? null);
            $changed++;
        }

        return back()->with('success', $changed ? "Regras atualizadas: {$changed}. Valem para os próximos primeiros pagamentos." : 'Nenhuma regra foi alterada.');
    }
}

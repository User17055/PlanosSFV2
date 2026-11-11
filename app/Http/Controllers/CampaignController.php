<?php

namespace App\Http\Controllers;

use App\Models\{Campaign, Proposal};
use App\Services\Domain\{Audit, BillingSchedule};
use Illuminate\Http\Request;

/** Campanhas de mensalidades grátis e os contratos que ganharam a isenção (só administração central). */
class CampaignController extends Controller
{
    public function index(Request $request, BillingSchedule $schedule)
    {
        $campaigns = Campaign::withCount(['proposals' => fn ($q) => $q->where('status', 'accepted')])->orderByDesc('starts_on')->orderByDesc('id')->get();
        $chosen = $request->integer('campanha') ?: null;
        $proposals = Proposal::where('free_months', '>', 0)->when($chosen, fn ($q) => $q->where('campaign_id', $chosen))
            ->with(['customer:id,name', 'pet:id,name', 'productVersion:id,name', 'campaign:id,name', 'creator:id,name,role', 'subscription.invoices'])
            ->orderByDesc('id')->paginate(30)->withQueryString();
        // Primeira mensalidade: a mesma conta da cobrança na Vindi (fechamento + meses grátis).
        $firstBill = fn (Proposal $p) => $p->accepted_at ? $schedule->firstRecurringBilling($p->accepted_at, null, $p->free_months) : null;
        return view('campaigns.index', compact('campaigns', 'proposals', 'chosen', 'firstBill'));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'free_months' => 'required|integer|min:1|max:12',
            'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on',
        ]);
        return $data + ['whole_site' => $request->boolean('whole_site'), 'active' => $request->boolean('active', true)];
    }

    public function store(Request $request)
    {
        $campaign = Campaign::create($this->validated($request));
        Audit::record('campaign.created', $campaign, $request->user(), $campaign->only(['name', 'free_months', 'starts_on', 'ends_on', 'whole_site']), null, null);
        return redirect()->route('campaigns.index')->with('success', 'Campanha criada.');
    }

    public function update(Request $request, Campaign $campaign)
    {
        $campaign->update($this->validated($request));
        Audit::record('campaign.updated', $campaign, $request->user(), $campaign->only(['name', 'free_months', 'starts_on', 'ends_on', 'whole_site', 'active']), null, null);
        return redirect()->route('campaigns.index')->with('success', 'Campanha atualizada. Propostas já abertas mantêm o que foi oferecido.');
    }
}

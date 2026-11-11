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
        return $data;
    }

    /** Só uma campanha vale no site todo: a principal. Marcar uma tira a marca da anterior. */
    private function makePrincipal(Campaign $campaign): void
    {
        Campaign::whereKeyNot($campaign->id)->where('whole_site', true)->update(['whole_site' => false]);
        $campaign->update(['whole_site' => true, 'active' => true]);
    }

    public function store(Request $request)
    {
        $campaign = Campaign::create($this->validated($request) + ['whole_site' => false, 'active' => true]);
        if ($request->boolean('whole_site')) $this->makePrincipal($campaign);
        Audit::record('campaign.created', $campaign, $request->user(), $campaign->only(['name', 'free_months', 'starts_on', 'ends_on', 'whole_site']), null, null);
        return redirect()->route('campaigns.index')->with('success', 'Campanha criada.');
    }

    public function update(Request $request, Campaign $campaign)
    {
        $campaign->update($this->validated($request));
        Audit::record('campaign.updated', $campaign, $request->user(), $campaign->only(['name', 'free_months', 'starts_on', 'ends_on', 'whole_site', 'active']), null, null);
        return redirect()->route('campaigns.index')->with('success', 'Campanha atualizada. Propostas já abertas mantêm o que foi oferecido.');
    }

    public function principal(Request $request, Campaign $campaign)
    {
        $this->makePrincipal($campaign);
        Audit::record('campaign.principal', $campaign, $request->user(), ['whole_site' => true], null, null);
        return redirect()->route('campaigns.index')->with('success', '“'.$campaign->name.'” agora é a campanha principal: vale no site todo.');
    }

    public function notPrincipal(Request $request, Campaign $campaign)
    {
        $campaign->update(['whole_site' => false]);
        Audit::record('campaign.not_principal', $campaign, $request->user(), ['whole_site' => false], null, null);
        return redirect()->route('campaigns.index')->with('success', '“'.$campaign->name.'” deixou de valer no site todo. Na venda da equipe, ainda pode ser escolhida.');
    }

    public function archive(Request $request, Campaign $campaign)
    {
        $campaign->update(['active' => false, 'whole_site' => false]);
        Audit::record('campaign.archived', $campaign, $request->user(), ['active' => false], null, null);
        return redirect()->route('campaigns.index')->with('success', '“'.$campaign->name.'” foi arquivada. Quem já entrou nela continua com os meses grátis.');
    }

    public function restore(Request $request, Campaign $campaign)
    {
        $campaign->update(['active' => true]);
        Audit::record('campaign.restored', $campaign, $request->user(), ['active' => true], null, null);
        return redirect()->route('campaigns.index')->with('success', '“'.$campaign->name.'” voltou para as campanhas ativas.');
    }
}

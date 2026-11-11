<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Services\Domain\Audit;
use Illuminate\Http\Request;

/** Cadastro das campanhas de mensalidades grátis, na tela de planos (só administração central). */
class CampaignController extends Controller
{
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
        return redirect()->to(route('catalog.index').'#campanhas')->with('success', 'Campanha criada.');
    }

    public function update(Request $request, Campaign $campaign)
    {
        $campaign->update($this->validated($request));
        Audit::record('campaign.updated', $campaign, $request->user(), $campaign->only(['name', 'free_months', 'starts_on', 'ends_on', 'whole_site', 'active']), null, null);
        return redirect()->to(route('catalog.index').'#campanhas')->with('success', 'Campanha atualizada. Propostas já abertas mantêm o que foi oferecido.');
    }
}

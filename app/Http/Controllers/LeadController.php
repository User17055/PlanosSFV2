<?php

namespace App\Http\Controllers;

use App\Models\{Franchise,Lead};
use App\Services\AccessService;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    /** Campos que o Pluga costuma mandar, em português ou inglês. O que não estiver aqui vai para "extra". */
    private const ALIASES = [
        'name' => ['name', 'nome', 'full_name', 'nome_completo'],
        'phone' => ['phone', 'telefone', 'phone_number', 'celular', 'whatsapp'],
        'email' => ['email', 'e-mail'],
        'pet_name' => ['pet', 'pet_name', 'nome_do_pet', 'nome_pet'],
        'external_id' => ['lead_id', 'id'],
        'campaign' => ['campaign', 'campanha', 'campaign_name', 'ad_name', 'form_name'],
    ];

    /** Recebe um lead do Pluga (Facebook Lead Ads). O endereço leva um código secreto; sem ele ninguém grava lead. */
    public function intake(Request $request, string $token)
    {
        $expected = (string) config('services.leads.token');
        abort_unless($expected !== '' && hash_equals($expected, $token), 404);

        $payload = $request->json()->all() ?: $request->all();
        $flat = [];
        foreach ($payload as $key => $value) {
            if (is_scalar($value)) $flat[mb_strtolower((string) $key)] = trim((string) $value);
        }
        $pick = function (string $field) use ($flat) {
            foreach (self::ALIASES[$field] as $alias) {
                if (($flat[$alias] ?? '') !== '') return $flat[$alias];
            }
            return null;
        };
        $data = array_map($pick, array_combine(array_keys(self::ALIASES), array_keys(self::ALIASES)));
        $data['phone'] = $data['phone'] ? preg_replace('/\D/', '', $data['phone']) : null;
        if (! $data['phone'] && ! $data['email']) {
            return response()->json(['received' => false, 'error' => 'Informe telefone ou e-mail do lead.'], 422);
        }
        if ($data['email'] && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $data['email'] = null;

        // O Pluga pode repetir o envio: o mesmo lead (pelo id do Facebook ou pelo mesmo contato em 10 min) não duplica.
        $duplicate = Lead::query()
            ->when($data['external_id'], fn ($q) => $q->where('external_id', mb_substr($data['external_id'], 0, 80)),
                fn ($q) => $q->where('created_at', '>=', now()->subMinutes(10))
                    ->where(fn ($w) => $w->when($data['phone'], fn ($p) => $p->where('phone', $data['phone']))->when($data['email'], fn ($e) => $e->orWhere('email', $data['email']))))
            ->first();
        if ($duplicate) return response()->json(['received' => true, 'duplicate' => true, 'id' => $duplicate->id]);

        $used = array_merge(...array_values(self::ALIASES));
        $extra = array_slice(array_map(fn ($v) => mb_substr($v, 0, 500), array_diff_key($flat, array_flip($used))), 0, 30);
        $franchise = Franchise::where('code', config('services.leads.franchise_code'))->where('active', true)->first() ?? Franchise::where('active', true)->orderBy('id')->firstOrFail();
        $lead = Lead::create([
            'originating_franchise_id' => $franchise->id,
            'external_id' => $data['external_id'] ? mb_substr($data['external_id'], 0, 80) : null,
            'name' => mb_substr($data['name'] ?: 'Sem nome', 0, 150),
            'phone' => $data['phone'] ? mb_substr($data['phone'], 0, 30) : null,
            'email' => $data['email'] ? mb_substr($data['email'], 0, 190) : null,
            'pet_name' => $data['pet_name'] ? mb_substr($data['pet_name'], 0, 100) : null,
            'campaign' => $data['campaign'] ? mb_substr($data['campaign'], 0, 160) : null,
            'extra' => $extra ?: null,
        ]);
        return response()->json(['received' => true, 'id' => $lead->id], 201);
    }

    public function index(Request $request, AccessService $access)
    {
        $status = $request->query('situacao');
        $query = $access->scope(Lead::query(), $request->user())->with('handler');
        $counts = (clone $query)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $leads = $query->when(isset(Lead::STATUSES[$status]), fn ($q) => $q->where('status', $status))->orderByDesc('id')->paginate(20)->withQueryString();
        return view('leads.index', compact('leads', 'counts', 'status'));
    }

    public function update(Request $request, Lead $lead, AccessService $access)
    {
        $access->franchise($request->user(), (int) $lead->originating_franchise_id);
        $data = $request->validate(['status' => 'required|in:'.implode(',', array_keys(Lead::STATUSES))]);
        $lead->update(['status' => $data['status'], 'handled_by' => $request->user()->id, 'contacted_at' => $lead->contacted_at ?? now()]);
        return back()->with('success', 'Lead atualizado.');
    }
}

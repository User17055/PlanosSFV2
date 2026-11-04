<?php

namespace App\Http\Controllers;

use App\Models\{Franchise,Lead,User};
use App\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache,Log};
use Illuminate\Support\{Arr,Str};

class LeadController extends Controller
{
    private const REFUSED = 'leads:last-refused';

    /** Campos que o Pluga costuma mandar, em português ou inglês (minúsculas, sem acento, "_" no lugar de espaço). O que não estiver aqui vai para "extra". */
    private const ALIASES = [
        'name' => ['name', 'nome', 'full_name', 'nome_completo'],
        'phone' => ['phone', 'telefone', 'phone_number', 'celular', 'whatsapp'],
        'email' => ['email', 'e_mail'],
        'pet_name' => ['pet', 'pet_name', 'nome_do_pet', 'nome_pet'],
        'external_id' => ['lead_id', 'id'],
        'campaign' => ['campaign', 'campanha', 'campaign_name', 'ad_name', 'form_name'],
    ];

    /** Formulário novo costuma vir com a pergunta escrita de outro jeito ("Qual o seu WhatsApp?"); sem isto, o lead seria recusado. */
    private const LOOSE = ['name' => '/nome|name/', 'phone' => '/fone|phone|celular|whats|zap/', 'email' => '/mail/'];

    /** "nome_do_pet" e "campaign_name" têm "nome" e "name", mas não são da pessoa. */
    private const NOT_PERSON = '/pet|animal|campa|anuncio|formulario|form_|ad_|adset|page|pagina/';

    /** Recebe um lead do Pluga (Facebook Lead Ads). O endereço leva um código secreto; sem ele ninguém grava lead. */
    public function intake(Request $request, string $token)
    {
        $expected = (string) config('services.leads.token');
        if ($expected === '' || ! hash_equals($expected, $token)) {
            Log::warning($expected === '' ? 'Webhook de leads: LEADS_WEBHOOK_TOKEN não configurado.' : 'Webhook de leads: endereço com código errado.');
            abort(404);
        }

        $flat = [];
        foreach (Arr::dot($request->json()->all() ?: $request->all()) as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') $flat[Str::slug(Str::afterLast((string) $key, '.'), '_')] ??= trim((string) $value);
        }
        $keyOf = function (string $field) use ($flat) {
            foreach (self::ALIASES[$field] as $alias) {
                if (isset($flat[$alias])) return $alias;
            }
            foreach (isset(self::LOOSE[$field]) ? array_keys($flat) : [] as $key) {
                if (preg_match(self::LOOSE[$field], $key) && ! preg_match(self::NOT_PERSON, $key)) return $key;
            }
            return null;
        };
        $keys = array_map($keyOf, array_combine(array_keys(self::ALIASES), array_keys(self::ALIASES)));
        // Vindo o número e o nome da campanha, fica o nome: é ele que diz o plano, a cidade e onde o anúncio roda.
        foreach (['campaign', 'campanha', 'campaign_name'] as $alias) {
            if (isset($flat[$alias]) && ! ctype_digit($flat[$alias])) {
                $keys['campaign'] = $alias;
                break;
            }
        }
        $data = array_map(fn ($key) => $key === null ? null : $flat[$key], $keys);
        $data['phone'] = $data['phone'] ? (preg_replace('/\D/', '', $data['phone']) ?: null) : null;
        // O teste do Facebook manda texto no lugar dos dados ("<test lead: dummy data for phone_number>"): entra marcado como teste, sem exigir contato.
        $isTest = collect($flat)->contains(fn ($v) => str_starts_with($v, '<test lead:'));
        if (! $isTest && ! $data['phone'] && ! $data['email']) {
            // Guarda só os nomes dos campos, nunca os valores: dado de cliente não vai para log nem para a tela.
            Log::warning('Webhook de leads: envio recusado, sem telefone nem e-mail reconhecível.', ['campos' => array_keys($flat)]);
            Cache::put(self::REFUSED, ['at' => now()->timestamp, 'fields' => array_slice(array_keys($flat), 0, 30)], now()->addDays(7));
            return response()->json(['received' => false, 'error' => 'Informe telefone ou e-mail do lead.'], 422);
        }
        if ($data['email'] && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $data['email'] = null;

        // O Pluga pode repetir o envio: o mesmo lead (mesmo id e contato, ou mesmo contato em 10 min) não duplica.
        $hasContact = $data['phone'] || $data['email'];
        $sameContact = fn ($q) => $q->where(fn ($w) => $w->when($data['phone'], fn ($p) => $p->where('phone', $data['phone']))->when($data['email'], fn ($e) => $e->orWhere('email', $data['email'])));
        $externalId = $data['external_id'] ? mb_substr($data['external_id'], 0, 80) : null;
        $duplicate = $externalId ? Lead::where('external_id', $externalId)->when($hasContact, $sameContact)->first() : null;
        // Id repetido com outro contato não é reenvio: em "id" está vindo um valor fixo (como o do formulário). O lead entra, sem o id.
        if ($externalId && ! $duplicate && Lead::where('external_id', $externalId)->exists()) $externalId = null;
        if (! $duplicate && ! $externalId && $hasContact) $duplicate = $sameContact(Lead::where('created_at', '>=', now()->subMinutes(10)))->first();
        if ($duplicate) return response()->json(['received' => true, 'duplicate' => true, 'id' => $duplicate->id]);

        $used = array_merge(array_filter($keys), ...array_values(self::ALIASES));
        $extra = array_slice(array_map(fn ($v) => mb_substr($v, 0, 500), array_diff_key($flat, array_flip($used))), 0, 30);
        $franchise = Franchise::where('code', config('services.leads.franchise_code'))->where('active', true)->first() ?? Franchise::where('active', true)->orderBy('id')->firstOrFail();
        $lead = Lead::create([
            'originating_franchise_id' => $franchise->id,
            'external_id' => $externalId,
            'name' => mb_substr($data['name'] ?: 'Sem nome', 0, 150),
            'phone' => $data['phone'] ? mb_substr($data['phone'], 0, 30) : null,
            'email' => $data['email'] ? mb_substr($data['email'], 0, 190) : null,
            'pet_name' => $data['pet_name'] ? mb_substr($data['pet_name'], 0, 100) : null,
            'campaign' => $data['campaign'] ? mb_substr($data['campaign'], 0, 160) : null,
            'source' => $isTest ? 'teste' : 'facebook_lead_ads',
            'extra' => $extra ?: null,
        ]);
        return response()->json(['received' => true, 'id' => $lead->id], 201);
    }

    public function index(Request $request, AccessService $access)
    {
        $request->validate(['q' => 'nullable|string|max:80', 'situacao' => 'nullable|in:'.implode(',', array_keys(Lead::STATUSES)), 'origem' => 'nullable|string|max:60']);
        $origin = $request->query('origem');
        $status = $request->query('situacao');
        $q = trim((string) $request->query('q'));
        $query = $access->scope(Lead::query(), $request->user())->with('handler');
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $digits = preg_replace('/\D/', '', $q);
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)
                ->when(strlen($digits) >= 4, fn ($p) => $p->orWhere('phone', 'like', '%'.$digits.'%')));
        }
        // shortcut: separa as origens em memória, com a mesma regra da tela; troque por uma coluna própria se passar de ~20 mil leads.
        $origins = (clone $query)->get(['id', 'campaign', 'extra', 'source'])->groupBy(fn ($lead) => $lead->originGroup());
        $originOptions = collect(['Instagram' => 0, 'Facebook' => 0, 'Google' => 0])->merge($origins->map->count());
        if ($origin) {
            $query->whereIn('id', ($origins[$origin] ?? collect())->pluck('id'));
        }
        // Os contadores seguem a busca, mas não o filtro de situação, para os botões mostrarem quantos há em cada uma.
        $counts = (clone $query)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        // Quem ainda não foi chamado fica no topo, mesmo em "Todos": lead novo não pode se perder no meio do histórico.
        $leads = $query->when($status, fn ($w) => $w->where('status', $status))->orderByRaw("case when status = 'new' then 0 else 1 end")->orderByDesc('id')->paginate(20)->withQueryString();
        return view('leads.index', compact('leads', 'counts', 'status', 'q', 'origin', 'originOptions') + ['intake' => $this->intakeStatus($request->user(), $access)]);
    }

    /** Painel dos leads: de onde vieram, quantos a equipe chamou e quantos viraram cliente. Lead de teste fica de fora. */
    public function dashboard(Request $request, AccessService $access)
    {
        $days = (int) ($request->validate(['dias' => 'nullable|in:7,30,90'])['dias'] ?? 30);
        $start = now('America/Sao_Paulo')->startOfDay()->subDays($days - 1);
        $real = fn () => $access->scope(Lead::query(), $request->user())->where('source', '!=', 'teste');
        // shortcut: soma os leads do período em memória; troque por consultas agrupadas se um período passar de ~20 mil leads.
        $leads = $real()->where('created_at', '>=', $start->copy()->utc())->with('handler:id,name')
            ->get(['id', 'status', 'campaign', 'extra', 'created_at', 'contacted_at', 'handled_by']);

        $sum = fn ($group) => ['total' => $group->count(), 'called' => $group->where('status', '!=', 'new')->count(), 'converted' => $group->where('status', 'converted')->count()];
        $breakdown = fn (callable $key) => $leads->groupBy($key)->map($sum)->sortByDesc('total');
        $minutes = $leads->filter(fn ($lead) => $lead->contacted_at)->map(fn ($lead) => $lead->created_at->diffInMinutes($lead->contacted_at))->median();
        $perDay = $leads->countBy(fn ($lead) => $lead->created_at->timezone('America/Sao_Paulo')->format('Y-m-d'));

        return view('leads.dashboard', [
            'days' => $days,
            'intake' => $this->intakeStatus($request->user(), $access),
            'totals' => $sum($leads) + ['lost' => $leads->where('status', 'lost')->count()],
            'previous' => $real()->where('created_at', '>=', $start->copy()->subDays($days)->utc())->where('created_at', '<', $start->copy()->utc())->count(),
            'waitingTotal' => $real()->where('status', 'new')->count(),
            'waiting' => $real()->where('status', 'new')->oldest('id')->limit(5)->get(),
            'firstCall' => $minutes === null ? null : ($minutes < 60 ? round($minutes).' min' : ($minutes < 2880 ? round($minutes / 60).' h' : round($minutes / 1440).' dias')),
            'perDay' => collect(range(0, $days - 1))->mapWithKeys(fn ($i) => [$start->copy()->addDays($i)->format('Y-m-d') => 0])->merge($perDay),
            'campaigns' => $breakdown(fn ($lead) => $lead->campaign ? $lead->campaignLabel() : 'Sem nome de campanha'),
            'ads' => $leads->contains(fn ($lead) => isset($lead->extra['anuncio'])) ? $breakdown(fn ($lead) => $lead->extra['anuncio'] ?? 'Não informado') : collect(),
            'species' => $leads->contains(fn ($lead) => isset($lead->extra['especie_do_pet'])) ? $breakdown(fn ($lead) => isset($lead->extra['especie_do_pet']) ? mb_convert_case($lead->extra['especie_do_pet'], MB_CASE_TITLE) : 'Não informado') : collect(),
            'team' => $breakdown(fn ($lead) => $lead->handler?->name ?? '')->forget(''),
        ]);
    }

    /** Último lead e problemas no recebimento: a resposta para "chegou no Facebook e não apareceu aqui". Configuração só aparece para quem pode resolver. */
    private function intakeStatus(User $user, AccessService $access): array
    {
        $admin = in_array($user->role, ['central_admin', 'manager'], true);
        return [
            'last' => $access->scope(Lead::query(), $user)->latest('id')->first(['id', 'name', 'created_at']),
            'off' => $admin && (string) config('services.leads.token') === '',
            'refused' => $admin ? Cache::get(self::REFUSED) : null,
            'admin' => $admin,
        ];
    }

    /** Só lead de teste (o do Facebook) pode ser apagado; lead real fica no histórico. */
    public function destroy(Request $request, Lead $lead, AccessService $access)
    {
        $access->franchise($request->user(), (int) $lead->originating_franchise_id);
        abort_unless($lead->source === 'teste', 422, 'Só leads de teste podem ser apagados.');
        $lead->delete();
        return back()->with('success', 'Lead de teste apagado.');
    }

    public function update(Request $request, Lead $lead, AccessService $access)
    {
        $access->franchise($request->user(), (int) $lead->originating_franchise_id);
        $data = $request->validate(['status' => 'required|in:'.implode(',', array_keys(Lead::STATUSES))]);
        $lead->update(['status' => $data['status'], 'handled_by' => $request->user()->id, 'contacted_at' => $lead->contacted_at ?? now()]);
        return back()->with('success', 'Lead atualizado.');
    }
}

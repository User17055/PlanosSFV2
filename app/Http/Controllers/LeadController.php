<?php

namespace App\Http\Controllers;

use App\Models\{Franchise,Lead,User};
use App\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache,Log};
use Illuminate\Support\{Arr,Carbon,Str};
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    private const REFUSED = 'leads:last-refused';

    private const FILTERS = ['dias' => 'nullable|in:7,30,90', 'de' => 'nullable|date_format:Y-m-d', 'ate' => 'nullable|date_format:Y-m-d', 'conversao' => 'nullable|string|max:30', 'trafego' => 'nullable|string|max:20', 'entrada' => 'nullable|string|max:20', 'origem' => 'nullable|string|max:60', 'campanha' => 'nullable|string|max:200', 'responsavel' => 'nullable|string|max:150'];

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
        $lead = Lead::create([
            'originating_franchise_id' => $this->intakeFranchise()->id,
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

    /** Formulário do WhatsApp do site: quem pede o plano entra na lista, com a origem que o site guardou no cookie sf_origem. A chave do site é conferida em SiteGateway. */
    public function fromSite(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|min:2|max:150', 'phone' => 'required|string|max:30', 'origin' => 'nullable|string|max:20', 'campaign' => 'nullable|string|max:120', 'paid' => 'nullable|boolean']);
        $phone = preg_replace('/\D/', '', $data['phone']);
        if (strlen($phone) < 10 || strlen($phone) > 13) {
            return response()->json(['received' => false, 'errors' => ['phone' => ['Telefone inválido.']]], 422);
        }
        // Mesma regra do Pluga: o mesmo contato em 10 min é clique repetido, não lead novo.
        if (Lead::where('phone', $phone)->where('created_at', '>=', now()->subMinutes(10))->exists()) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }
        $lead = Lead::create([
            'originating_franchise_id' => $this->intakeFranchise()->id,
            'name' => $data['name'],
            'phone' => $phone,
            'campaign' => ($data['campaign'] ?? null) ?: 'Formulário do WhatsApp',
            'source' => 'site_whatsapp',
            // O cookie só distingue estas três redes. Visita direta ou vinda de outro site fica guardada como "site" e aparece como Google (Lead::channel).
            'extra' => ['plataforma' => in_array($data['origin'] ?? null, ['google', 'instagram', 'facebook'], true) ? $data['origin'] : 'site', 'trafego' => $request->boolean('paid') ? 'pago' : 'organico', 'formulario' => 'WhatsApp do site'],
        ]);
        return response()->json(['received' => true, 'id' => $lead->id], 201);
    }

    private function intakeFranchise(): Franchise
    {
        return Franchise::where('code', config('services.leads.franchise_code'))->where('active', true)->first() ?? Franchise::where('active', true)->orderBy('id')->firstOrFail();
    }

    /** Conversão, tráfego e origem seguem regras da tela, por isso a lista e o painel filtram em memória. */
    private function dimensions(): array
    {
        return [
            'conversao' => fn (Lead $lead) => $lead->conversion(),
            'trafego' => fn (Lead $lead) => $lead->traffic(),
            'entrada' => fn (Lead $lead) => $lead->entry(),
            'origem' => fn (Lead $lead) => $lead->originGroup(),
            'campanha' => fn (Lead $lead) => $lead->campaign ? $lead->campaignLabel() : 'Sem nome de campanha',
            'responsavel' => fn (Lead $lead) => $lead->handler?->name ?? 'Na fila',
        ];
    }

    private function matcher(array $chosen): \Closure
    {
        $dimensions = $this->dimensions();
        return fn (Lead $lead) => collect($chosen)->every(fn ($value, $key) => $dimensions[$key]($lead) === $value);
    }

    /** Período no horário de São Paulo: 7, 30 ou 90 dias até hoje, ou de/até à escolha, até um ano. Sem nada escolhido, usa $days; sem $days, não há período. */
    private function period(array $input, ?int $days = null): array
    {
        $day = fn (string $value) => Carbon::createFromFormat('Y-m-d', $value, 'America/Sao_Paulo')->startOfDay();
        $today = now('America/Sao_Paulo')->startOfDay();
        $days = (int) (($input['dias'] ?? null) ?: $days);
        $from = $input['de'] ?? null;
        $to = $input['ate'] ?? null;
        if (! $from && ! $to && ! $days) {
            return [null, null];
        }
        $end = $to ? $day($to)->min($today) : $today;
        $start = $from ? $day($from) : $end->copy()->subDays(($days ?: 30) - 1);
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }
        return [$start->max($end->copy()->subDays(365)), $end];
    }

    public function index(Request $request, AccessService $access)
    {
        $input = $request->validate(self::FILTERS + ['q' => 'nullable|string|max:80', 'situacao' => 'nullable|in:'.implode(',', array_keys(Lead::STATUSES))]);
        $status = $input['situacao'] ?? null;
        $q = trim((string) ($input['q'] ?? ''));
        $user = $request->user();
        $seesAll = in_array($user->role, Lead::SEE_ALL, true);
        $query = $access->scope(Lead::visibleTo($user), $user)->with('handler');
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $digits = preg_replace('/\D/', '', $q);
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)
                ->when(strlen($digits) >= 4, fn ($p) => $p->orWhere('phone', 'like', '%'.$digits.'%')));
        }
        [$start, $end] = $this->period($input);
        if ($start) {
            $query->whereBetween('created_at', [$start->copy()->utc(), $end->copy()->endOfDay()->utc()]);
        }
        $dimensions = $this->dimensions();
        $chosen = array_filter(Arr::only($input, array_keys($dimensions)));
        // shortcut: separa os filtros em memória, com a mesma regra da tela; troque por colunas próprias se passar de ~20 mil leads.
        $rows = (clone $query)->get(['id', 'status', 'campaign', 'extra', 'source', 'handled_by']);
        $filterOptions = collect(['conversao' => array_keys(Lead::CONVERSIONS), 'trafego' => Lead::TRAFFIC, 'entrada' => ['Site', 'Anúncio'], 'origem' => ['Instagram', 'Facebook', 'Google']] + ($seesAll ? ['responsavel' => ['Na fila']] : []))
            ->map(fn ($names, $key) => collect($names)->mapWithKeys(fn ($name) => [$name => 0])->merge($rows->countBy($dimensions[$key])));
        if ($chosen) {
            $query->whereIn('id', $rows->filter($this->matcher($chosen))->pluck('id'));
        }
        // Os contadores seguem a busca e os filtros, mas não a situação, para os botões mostrarem quantos há em cada uma.
        $counts = (clone $query)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        // Quem ainda não foi chamado fica no topo, mesmo em "Todos": lead novo não pode se perder no meio do histórico.
        $leads = $query->when($status, fn ($w) => $w->where('status', $status))->orderByRaw("case when status = 'new' then 0 else 1 end")->orderByDesc('id')->paginate(20)->withQueryString();
        $canAssign = $seesAll && $user->canUse('leads.assign');
        return view('leads.index', compact('leads', 'counts', 'status', 'q', 'filterOptions', 'start', 'end', 'seesAll', 'canAssign') + [
            'assignees' => $canAssign ? $leads->pluck('originating_franchise_id')->unique()->mapWithKeys(fn ($id) => [$id => $this->assignees((int) $id)]) : collect(),
            'applied' => array_filter(['q' => $q] + Arr::only($input, array_keys(self::FILTERS))),
            'filtersOn' => count($chosen) + ($start ? 1 : 0),
            'intake' => $this->intakeStatus($request->user(), $access),
        ]);
    }

    /** Painel dos leads: de onde vieram, quantos a equipe chamou e quem converteu. Os filtros mudam todos os números. Lead de teste fica de fora. */
    public function dashboard(Request $request, AccessService $access)
    {
        $input = $request->validate(self::FILTERS);
        [$start, $end] = $this->period($input, 30);
        $days = (int) $start->diffInDays($end) + 1;
        $dimensions = $this->dimensions();
        $chosen = array_filter(Arr::only($input, array_keys($dimensions)));
        $matches = $this->matcher($chosen);
        $seesAll = in_array($request->user()->role, Lead::SEE_ALL, true);
        $real = fn () => $access->scope(Lead::visibleTo($request->user()), $request->user())->where('source', '!=', 'teste')->with('handler:id,name');
        // shortcut: soma os leads do período em memória; troque por consultas agrupadas se um período passar de ~20 mil leads.
        $between = fn ($from, $to) => $real()->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->endOfDay()->utc()])
            ->get(['id', 'name', 'status', 'campaign', 'extra', 'source', 'created_at', 'updated_at', 'contacted_at', 'handled_by']);
        $inPeriod = $between($start, $end);
        $leads = $inPeriod->filter($matches);
        $waiting = $real()->where('status', 'new')->oldest('id')->get()->filter($matches);

        $sum = fn ($group) => ['total' => $group->count(), 'called' => $group->where('status', '!=', 'new')->count(), 'converted' => $group->where('status', 'converted')->count()];
        $breakdown = fn (callable $key) => $leads->groupBy($key)->map($sum)->sortByDesc('total');
        $minutes = $leads->filter(fn ($lead) => $lead->contacted_at)->map(fn ($lead) => $lead->created_at->diffInMinutes($lead->contacted_at))->median();
        $perDay = $leads->countBy(fn ($lead) => $lead->created_at->timezone('America/Sao_Paulo')->format('Y-m-d'));

        return view('leads.dashboard', [
            'days' => $days,
            'start' => $start,
            'end' => $end,
            'endsToday' => $end->isSameDay(now('America/Sao_Paulo')),
            'chosen' => $chosen,
            // O valor escolhido fica na lista mesmo sem lead no período, para o filtro ativo continuar visível.
            'filterOptions' => collect(['conversao' => 'Conversão', 'trafego' => 'Tráfego', 'entrada' => 'Por onde entrou', 'origem' => 'Origem', 'campanha' => 'Campanha'] + ($seesAll ? ['responsavel' => 'Atendente'] : []))
                ->map(fn ($label, $key) => [$label, $inPeriod->toBase()->map($dimensions[$key])->merge(['conversao' => array_keys(Lead::CONVERSIONS), 'trafego' => Lead::TRAFFIC, 'entrada' => ['Site', 'Anúncio']][$key] ?? [])->push($chosen[$key] ?? null)->filter()->unique()->sort()->values()]),
            'intake' => $this->intakeStatus($request->user(), $access),
            'totals' => $sum($leads) + ['lost' => $leads->where('status', 'lost')->count()],
            'previous' => $between($start->copy()->subDays($days), $start->copy()->subDay())->filter($matches)->count(),
            'waitingTotal' => $waiting->count(),
            'waiting' => $waiting->take(5),
            'firstCall' => $minutes === null ? null : ($minutes < 60 ? round($minutes).' min' : ($minutes < 2880 ? round($minutes / 60).' h' : round($minutes / 1440).' dias')),
            'perDay' => collect(range(0, $days - 1))->mapWithKeys(fn ($i) => [$start->copy()->addDays($i)->format('Y-m-d') => 0])->merge($perDay),
            'traffic' => $breakdown($dimensions['trafego']),
            'entries' => $breakdown($dimensions['entrada']),
            'origins' => $breakdown($dimensions['origem']),
            'campaigns' => $breakdown($dimensions['campanha']),
            'ads' => $leads->contains(fn ($lead) => isset($lead->extra['anuncio'])) ? $breakdown(fn ($lead) => $lead->extra['anuncio'] ?? 'Não informado') : collect(),
            'species' => $leads->contains(fn ($lead) => isset($lead->extra['especie_do_pet'])) ? $breakdown(fn ($lead) => isset($lead->extra['especie_do_pet']) ? mb_convert_case($lead->extra['especie_do_pet'], MB_CASE_TITLE) : 'Não informado') : collect(),
            'team' => $seesAll ? $breakdown($dimensions['responsavel'])->forget('Na fila')->sortByDesc('converted') : collect(),
            'converted' => $leads->where('status', 'converted')->sortByDesc('updated_at')->take(10),
        ]);
    }

    /** Último lead e problemas no recebimento: a resposta para "chegou no Facebook e não apareceu aqui". Configuração só aparece para quem pode resolver. */
    private function intakeStatus(User $user, AccessService $access): array
    {
        $admin = in_array($user->role, ['central_admin', 'manager'], true);
        return [
            'last' => $access->scope(Lead::visibleTo($user), $user)->latest('id')->first(['id', 'name', 'created_at']),
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
        $user = $request->user();
        $access->franchise($user, (int) $lead->originating_franchise_id);
        $data = $request->validate(['status' => 'required|in:'.implode(',', array_keys(Lead::STATUSES))]);
        // Quem mexe primeiro em um lead da fila fica com ele. A condição no próprio UPDATE resolve duas pessoas clicando juntas.
        if ($data['status'] !== 'new') {
            Lead::whereKey($lead->id)->whereNull('handled_by')->update(['handled_by' => $user->id]);
        }
        abort_unless(Lead::visibleTo($user)->whereKey($lead->id)->exists(), 403, 'Este lead já está com outra pessoa.');
        $lead->refresh()->update(['status' => $data['status'], 'contacted_at' => $data['status'] === 'new' ? $lead->contacted_at : ($lead->contacted_at ?? now())]);
        return back()->with('success', 'Lead atualizado.');
    }

    /** Escolhe com quem o lead fica; vazio devolve para a fila. A rota é só de Administração central e Gestão da unidade. */
    public function assign(Request $request, Lead $lead, AccessService $access)
    {
        $access->franchise($request->user(), (int) $lead->originating_franchise_id);
        $data = $request->validate(['handled_by' => ['nullable', 'integer', Rule::in($this->assignees((int) $lead->originating_franchise_id)->pluck('id'))]]);
        $lead->update(['handled_by' => $data['handled_by'] ?? null]);
        return back()->with('success', 'Responsável atualizado.');
    }

    /** Quem pode ficar com um lead: conta ativa, com acesso à tela de leads e à unidade dele. */
    private function assignees(int $franchiseId)
    {
        return User::where('active', true)->whereIn('role', User::AREAS['leads'][2])
            ->where(fn ($q) => $q->where('role', 'central_admin')->orWhereHas('franchises', fn ($f) => $f->whereKey($franchiseId)->where('user_franchises.active', true)))
            ->with('jobRole')->orderBy('name')->get()->filter->canOpen('leads')->values();
    }
}

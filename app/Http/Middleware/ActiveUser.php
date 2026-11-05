<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'Este acesso está desativado.');
        }
        // Quem entrou pelo link de assinatura não digitou senha: chega só àquele contrato, e o resto do painel pede a senha.
        $linked = $request->user() ? $request->session()->get('signing_link_proposal') : null;
        if ($linked && ! $this->signingLinkAllows($request, (int) $linked)) {
            $isGet = $request->isMethod('GET');
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort_unless($isGet, 403, 'Entre com sua senha para continuar.');
            return redirect()->route('login')->with('success', 'Para ver seu painel, entre com sua senha.');
        }
        // Perfil de atendimento: só enxerga os Leads. Qualquer outra tela volta para a lista; qualquer gravação fora dela é recusada.
        if ($request->user()?->role === 'attendant' && ! $request->routeIs('leads.*', 'logout')) {
            abort_unless($request->isMethod('GET'), 403, 'Seu perfil permite apenas a tela de Leads.');
            return redirect()->route('leads.index');
        }
        // Telas desmarcadas no cadastro do funcionário: abrir volta para a primeira tela liberada; gravar é recusado.
        $user = $request->user();
        // Cargo: rota sem opção marcada fica fechada. Abrir volta para a primeira tela liberada; gravar é recusado.
        if ($user?->job_role_id && ! $request->routeIs('logout') && ! $user->canUse((string) $request->route()->getName())) {
            $home = collect(User::areasFor($user->role))->first(fn ($key) => $user->canOpen($key));
            abort_unless($request->isMethod('GET') && $home, 403, 'Seu cargo não permite esta opção.');
            return redirect()->route($home === 'dashboard' ? 'dashboard' : $home.'.index');
        }
        if ($user?->areas !== null) {
            $area = collect(User::AREAS)->search(fn ($area) => $request->routeIs(...$area[1]));
            if ($area !== false && ! $user->canOpen($area)) {
                $home = collect($user->areas)->first(fn ($key) => in_array($key, User::areasFor($user->role), true));
                abort_unless($request->isMethod('GET') && $home, 403, 'Esta tela não está liberada para você.');
                return redirect()->route($home === 'dashboard' ? 'dashboard' : $home.'.index');
            }
        }
        return $next($request);
    }

    private function signingLinkAllows(Request $request, int $proposalId): bool
    {
        $route = $request->route();
        return match ($route->getName()) {
            'logout', 'sales.proposal' => true,
            'proposals.show', 'proposals.preview-pdf', 'proposals.contract', 'proposals.accept', 'proposals.regenerate-contract' => (int) data_get($route->parameter('proposal'), 'id', $route->parameter('proposal')) === $proposalId,
            'subscriptions.checkout' => (int) data_get($route->parameter('subscription'), 'proposal_id') === $proposalId,
            'contracts.download' => (int) data_get($route->parameter('document'), 'subscription.proposal_id') === $proposalId,
            default => false,
        };
    }
}

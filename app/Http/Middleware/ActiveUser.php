<?php

namespace App\Http\Middleware;

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
        // Perfil de atendimento: só enxerga os Leads. Qualquer outra tela volta para a lista; qualquer gravação fora dela é recusada.
        if ($request->user()?->role === 'attendant' && ! $request->routeIs('leads.*', 'logout')) {
            abort_unless($request->isMethod('GET'), 403, 'Seu perfil permite apenas a tela de Leads.');
            return redirect()->route('leads.index');
        }
        return $next($request);
    }
}

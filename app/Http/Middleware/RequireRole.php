<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if ($request->user() && in_array($request->user()->role, $roles, true)) {
            return $next($request);
        }
        // Administrador abrindo uma página só de cliente (ex.: entrar na Tudo pra Pet): escolhe a área de cliente e segue para ela.
        if ($request->user()?->role === 'central_admin' && in_array('customer', $roles, true) && $request->isMethod('GET')) {
            $request->session()->put('customer_intended', $request->fullUrl());
            return redirect()->route('account.choose');
        }
        abort(403, 'Seu perfil não permite esta operação.');
    }
}

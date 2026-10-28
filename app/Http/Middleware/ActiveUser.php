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
        if ($request->user()?->role==='customer' && !$request->user()->email_verified_at && !$request->routeIs('portal','verification.*','logout')) {
            return redirect()->route('portal')->with('error','Verifique seu e-mail antes de acessar os dados e contratos.');
        }
        return $next($request);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // O campo aceita e-mail ou CPF; o CPF vira o e-mail do único acesso ativo do cliente (sem acesso único, falha como qualquer outra tentativa).
    private function resolveEmail(string $login): string
    {
        if (str_contains($login,'@')) return $login;
        $cpf=preg_replace('/\D/','',$login);
        if ($cpf==='') return $login;
        $users=\App\Models\User::where('role','customer')->where('active',true)
            ->whereIn('customer_id',\App\Models\Customer::where('document',$cpf)->select('id'))->limit(2)->get();
        return $users->count()===1 ? $users->first()->email : $login;
    }

    // Entrada logo após confirmar o e-mail: link assinado, curto e de uso único, emitido só pela confirmação.
    public function confirmedAccess(Request $request, \App\Models\User $user)
    {
        $used = Cache::add('confirmed-access:'.hash('sha256',(string) $request->query('signature')), 1, now()->addMinutes(10));
        if (! $used || $user->role !== 'customer' || ! $user->active || ! $user->email_verified_at) {
            return redirect()->route('login')->with('error','Este link já foi usado ou expirou. Entre com seu e-mail ou CPF e sua senha.');
        }
        Auth::login($user);
        $request->session()->regenerate();
        return redirect()->route('portal');
    }

    // Vindo do site com a sessão do cliente aberta (cadastro, contrato, pagamento): link assinado de 2 minutos e uso único.
    public function siteAccess(Request $request, \App\Models\User $user)
    {
        $used = Cache::add('site-access:'.hash('sha256',(string) $request->query('signature')), 1, now()->addMinutes(5));
        if (! $used || $user->role !== 'customer' || ! $user->active) {
            return redirect()->route('login')->with('error','Este link já foi usado ou expirou. Entre com seu e-mail ou CPF e sua senha.');
        }
        Auth::login($user);
        $request->session()->regenerate();
        return redirect()->route('portal');
    }

    public function login(Request $request)
    {
        $intended=(string)$request->session()->get('url.intended','');
        $inviteToken=preg_match('~/convite/([A-Za-z0-9]{32,128})~',$intended,$matches)?$matches[1]:null;
        return view('auth.login',compact('inviteToken'));
    }

    public function authenticate(Request $request)
    {
        $data = $request->validate(['email' => 'required|string|max:255', 'password' => 'required|string']);
        $login = trim($data['email']);
        $valid = str_contains($login,'@') ? filter_var($login,FILTER_VALIDATE_EMAIL) : strlen(preg_replace('/\D/','',$login))===11;
        if (! $valid) throw ValidationException::withMessages(['email' => 'Informe um e-mail ou CPF válido.']);
        $data['email'] = $this->resolveEmail($login);
        $key = 'login:'.hash('sha256', strtolower($data['email']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => 'Aguarde alguns minutos antes de tentar novamente.']);
        }
        if (! Auth::attempt($data + ['active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['password' => 'E-mail, CPF ou senha incorretos.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $customerDestination = $request->session()->has('sf_suggested_product_version_id')
            ? route('proposals.create',['product_version_id'=>$request->session()->get('sf_suggested_product_version_id')]) : route('portal');
        $role=$request->user()->role;
        // Um destino guardado antes do login (ex.: /painel) só vale se o perfil pode abri-lo; senão o cliente cairia num 403.
        $intended=(string)$request->session()->get('url.intended','');
        if (! $this->roleCanOpen($role,$intended)) {
            $request->session()->forget('url.intended');
            // Destino só de cliente (ex.: entrar na Tudo pra Pet): o administrador segue para ele se escolher a área de cliente.
            if ($role === 'central_admin' && $this->roleCanOpen('customer',$intended)) $request->session()->put('customer_intended',$intended);
        }
        // A administração central escolhe entre o painel e a própria área de cliente; o destino guardado vale para o painel.
        if ($role === 'central_admin') {
            AccountSwitchController::linkSamePerson($request->user(),$data['password']);
            return redirect()->route('account.choose');
        }
        return redirect()->intended($role === 'customer' ? $customerDestination : route($role === 'attendant' ? 'leads.index' : 'dashboard'));
    }

    private function roleCanOpen(string $role,string $url): bool
    {
        if ($url==='') return true;
        try { $route=app('router')->getRoutes()->match(Request::create($url)); } catch (\Throwable) { return false; }
        foreach (app('router')->gatherRouteMiddleware($route) as $middleware) {
            if (str_starts_with($middleware,\App\Http\Middleware\RequireRole::class.':') && ! in_array($role,explode(',',substr($middleware,strlen(\App\Http\Middleware\RequireRole::class)+1)),true)) return false;
        }
        return true;
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}

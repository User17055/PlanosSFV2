<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $intended=(string)$request->session()->get('url.intended','');
        $inviteToken=preg_match('~/convite/([A-Za-z0-9]{32,128})~',$intended,$matches)?$matches[1]:null;
        return view('auth.login',compact('inviteToken'));
    }

    public function authenticate(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $key = 'login:'.hash('sha256', strtolower($data['email']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Aguarde alguns minutos antes de tentar novamente.']);
        }
        if (! Auth::attempt($data + ['active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'Não foi possível entrar com os dados informados.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $customerDestination = $request->session()->has('sf_suggested_product_version_id')
            ? route('proposals.create',['product_version_id'=>$request->session()->get('sf_suggested_product_version_id')]) : route('portal');
        return redirect()->intended($request->user()->role === 'customer' ? $customerDestination : route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}

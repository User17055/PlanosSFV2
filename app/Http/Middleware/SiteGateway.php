<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\{ArraySessionHandler,Store};
use Illuminate\Support\Facades\RateLimiter;

class SiteGateway
{
    public function handle(Request $request,Closure $next)
    {
        $request->headers->set('Accept','application/json');
        $key=(string)config('site_portal.key');
        abort_unless(config('site_portal.enabled') && strlen($key)>=32,503,'A contratação online está em preparação.');
        abort_unless(hash_equals($key,(string)$request->header('X-Site-Key')),401,'Acesso não autorizado.');
        abort_if(strlen($request->getContent())>65536,413,'Formulário excede o tamanho permitido.');
        $identity=$request->is('*/account-check','*/verification-resend') ? $request->header('X-Portal-IP',$request->ip())
            : ($request->bearerToken() ?: ($request->input('email') ?: $request->header('X-Portal-IP',$request->ip())));
        $bucket='site-api:'.hash('sha256',$request->path().'|'.strtolower((string)$identity));
        $max=$request->is('*/account-check','*/verification-resend')?20:($request->is('*/login')?5:($request->is('*/register','*/forgot-password','*/reset-password')?5:120));
        abort_if(RateLimiter::tooManyAttempts($bucket,$max),429,'Aguarde um minuto e tente novamente.');
        RateLimiter::hit($bucket,60);
        // Attribution services need a session, but API clients never share Laravel cookies.
        $session=new Store('portal_api_context',new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);
        $response=$next($request);
        $response->headers->set('Cache-Control','private, no-store');
        $response->headers->set('X-Content-Type-Options','nosniff');
        $response->headers->set('Referrer-Policy','no-referrer');
        return $response;
    }
}

<?php

namespace App\Http\Middleware;

use App\Services\Attribution\AttributionService;
use Closure;
use Illuminate\Http\Request;

class CaptureAcquisition
{
    public function handle(Request $request,Closure $next)
    {
        if($request->isMethod('GET') && (!$request->user() || $request->user()->role==='customer')) {
            $skip=$request->session()->pull('sf_skip_attribution_landing',false);
            $hasUtm=collect(['utm_source','utm_medium','utm_campaign','utm_content','utm_term'])->contains(fn($key)=>$request->filled($key));
            $referrerHost=parse_url($request->header('Referer',''),PHP_URL_HOST);
            $externalReferrer=$referrerHost && strcasecmp($referrerHost,$request->getHost())!==0;
            if(!$skip && (!$request->session()->has('sf_attribution') || $hasUtm || $externalReferrer)) app(AttributionService::class)->capture($request);
        }
        return $next($request);
    }
}

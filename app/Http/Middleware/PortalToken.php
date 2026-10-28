<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PortalToken
{
    public function handle(Request $request,Closure $next)
    {
        $raw=(string)$request->bearerToken();
        abort_unless(preg_match('/^[a-f0-9]{64}$/D',$raw),401,'Entre novamente na área do cliente.');
        $token=DB::table('portal_tokens')->where('token_hash',hash('sha256',$raw))->where('expires_at','>',now())->first();
        $user=$token?User::with('customer')->find($token->user_id):null;
        $valid=$user && $user->active && $user->role==='customer' && $user->customer?->active
            && hash_equals($token->password_fingerprint,hash_hmac('sha256',$user->password,(string)config('app.key')));
        abort_unless($valid,401,'Entre novamente na área do cliente.');
        $request->setUserResolver(fn()=>$user);
        $request->attributes->set('portal_token_id',$token->id);
        $request->attributes->set('portal_token_hash',$token->token_hash);
        return $next($request);
    }
}

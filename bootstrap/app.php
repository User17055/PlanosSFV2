<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/site-api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['active'=>\App\Http\Middleware\ActiveUser::class,'role'=>\App\Http\Middleware\RequireRole::class]);
        $middleware->validateCsrfTokens(except:['webhooks/vindi','webhooks/leads/*']);
        $middleware->redirectUsersTo(fn (\Illuminate\Http\Request $request) => $request->user()?->role === 'customer' ? '/portal' : '/painel');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, \Throwable $exception, \Illuminate\Http\Request $request) {
            $status=$response->getStatusCode();
            if(!$request->expectsJson() && in_array($status,[419,500],true)) {
                return response()->view('errors.recoverable',['status'=>$status],$status)->header('Cache-Control','private, no-store');
            }
            return $response;
        });
        $exceptions->render(function (\App\Services\Domain\DomainException $e, \Illuminate\Http\Request $request) {
            if ($request->user()) {
                try { \App\Models\AuditLog::create(['actor_id'=>$request->user()->id,'action'=>'operation.rejected','entity_type'=>'request','entity_id'=>0,'data'=>['code'=>$e->errorCode,'path'=>$request->path()]]); } catch (\Throwable) {}
            }
            if ($request->expectsJson()) return response()->json(['code'=>$e->errorCode,'message'=>$e->getMessage(),'details'=>$e->details],$e->httpStatus);
            if(in_array($e->httpStatus,[403,404],true)) return response($e->getMessage(),$e->httpStatus)->header('Content-Type','text/plain; charset=UTF-8');
            if (! $request->isMethod('GET')) return back()->withErrors(['operation'=>$e->getMessage()])->withInput($request->except(['password','password_confirmation','signature_data']));
            return response($e->getMessage(),$e->httpStatus)->header('Content-Type','text/plain; charset=UTF-8');
        });
    })->create();

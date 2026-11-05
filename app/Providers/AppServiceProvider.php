<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A Vindi aceita 120 consultas por minuto e manda um lote de avisos de uma vez na cobrança diária. A folga é da conciliação e do checkout.
        RateLimiter::for('vindi', fn () => Limit::perMinute(100));
    }
}

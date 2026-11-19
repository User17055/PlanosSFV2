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
        // A Vindi aceita 120 consultas por minuto. Metade é do tempo real (aviso de pagamento, cadastro), metade da conferência em lote,
        // para o pagamento de um cliente nunca esperar a rodada de 30 em 30 minutos. Juntos, os mesmos 100 por minuto de antes.
        RateLimiter::for('vindi', fn () => Limit::perMinute(50));
        RateLimiter::for('vindi-lote', fn () => Limit::perMinute(50));
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\ProcessVindiWebhookJob;
use App\Models\IntegrationEvent;
use App\Services\Integrations\VindiReconciler;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** O lote diário de avisos da Vindi não pode estourar o limite de 120 consultas por minuto da API. */
class VindiQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_above_the_limit_waits_in_line_without_using_up_attempts(): void
    {
        RateLimiter::clear(md5('vindi'));
        $job = (new ProcessVindiWebhookJob(1))->withFakeQueueInteractions();
        $this->assertInstanceOf(RateLimited::class, $job->middleware()[0]);
        $processed = 0;
        foreach (range(1, 100) as $i) {
            (new RateLimited('vindi'))->handle($job, function () use (&$processed) { $processed++; });
        }
        $this->assertSame(100, $processed);
        $job->assertNotReleased();

        (new RateLimited('vindi'))->handle($job, function () use (&$processed) { $processed++; });
        $this->assertSame(100, $processed);
        $job->assertReleased();

        // Quem espera a vez não vira falha: a desistência depende do prazo e dos erros de verdade.
        $this->assertFalse(property_exists($job, 'tries'));
        $this->assertSame(5, $job->maxExceptions);
        $this->assertGreaterThan(now()->addHours(5)->getTimestamp(), $job->retryUntil()->getTimestamp());
    }

    public function test_failure_logs_the_real_cause_without_customer_data(): void
    {
        Log::spy();
        $event = IntegrationEvent::create(['provider' => 'vindi', 'provider_account' => 'central', 'event_type' => 'bill_paid', 'entity_type' => 'invoice', 'entity_id' => 123, 'idempotency_key' => 'teste-429', 'status' => 'pending', 'received_at' => now()]);
        $reconciler = $this->mock(VindiReconciler::class);
        $reconciler->shouldReceive('reconcileBill')->once()->andThrow(new RequestException(new Response(new Psr7Response(429, [], '{"cliente":"Maria","cpf":"11144477735"}'))));

        try {
            (new ProcessVindiWebhookJob($event->id))->handle($reconciler);
            $this->fail('O aviso com falha precisa voltar para a fila.');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString('Maria', $exception->getMessage());
        }

        $this->assertSame('error', $event->fresh()->status);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $context['http'] === 429 && $context['tipo'] === 'RequestException'
            && $context['evento'] === $event->id && ! str_contains(json_encode($context), '11144477735') && ! str_contains(json_encode($context), 'Maria'));
    }
}

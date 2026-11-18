<?php

namespace Tests\Feature;

use App\Models\{Franchise,Lead};
use App\Services\Integrations\LeadAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Lead novo avisa no celular pelo ntfy, só nos horários configurados e sem dado do cliente. */
class LeadAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.leads.token' => 'segredo-de-teste', 'services.leads.franchise_code' => 'CENTRAL',
            'services.leads.alert.server' => 'https://ntfy.test', 'services.leads.alert.topic' => 'topico-de-teste']);
        Franchise::create(['name' => 'Canal central', 'code' => 'CENTRAL', 'active' => true]);
        Http::fake(['ntfy.test*' => Http::response(['id' => 'abc'])]);
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, 'America/Sao_Paulo'));
    }

    private function sendLead(string $phone = '18999990000')
    {
        return $this->postJson('/webhooks/leads/segredo-de-teste', ['nome' => 'Maria Teste', 'telefone' => $phone, 'email' => 'maria@example.test', 'pet' => 'Lua', 'campanha' => 'Plano Econômico - Novembro']);
    }

    public function test_new_lead_in_the_window_alerts_without_customer_data(): void
    {
        $this->at('2026-11-18 19:30'); // quarta, depois das 18h
        $this->sendLead()->assertCreated();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $sent = json_encode($request->data(), JSON_UNESCAPED_UNICODE);
            return $request->url() === 'https://ntfy.test' && $request['topic'] === 'topico-de-teste'
                && $request['title'] === 'Lead novo · Anúncio' && str_contains($request['message'], 'Nº '.Lead::sole()->id)
                && str_contains($request['message'], 'Plano Econômico') && $request['click'] === route('leads.index')
                && ! str_contains($sent, 'Maria') && ! str_contains($sent, '18999990000') && ! str_contains($sent, 'example.test') && ! str_contains($sent, 'Lua');
        });
        $this->assertNull(Lead::sole()->handled_by, 'O aviso não escolhe responsável.');
        $this->assertSame('new', Lead::sole()->status);
    }

    public function test_site_form_also_alerts_and_a_repeated_lead_does_not(): void
    {
        $this->at('2026-11-21 15:00'); // sábado, depois das 14h
        config(['site_portal.enabled' => true, 'site_portal.key' => 'chave-do-site-com-pelo-menos-32-caracteres']);
        $this->postJson('/api/site/v1/leads', ['name' => 'Ana', 'phone' => '(18) 98888-0000', 'origin' => 'instagram'], ['X-Site-Key' => 'chave-do-site-com-pelo-menos-32-caracteres'])->assertCreated();
        Http::assertSent(fn (Request $request) => $request['title'] === 'Lead novo · Site' && str_contains($request['message'], 'Instagram'));

        $this->sendLead()->assertCreated();
        $this->sendLead()->assertOk()->assertJsonPath('duplicate', true);
        Http::assertSentCount(2);
    }

    public function test_outside_the_window_or_without_topic_nothing_is_sent(): void
    {
        $this->at('2026-11-18 17:30'); // quarta, entre as 17h e as 18h
        $this->sendLead('18911110000')->assertCreated();
        config(['services.leads.alert.topic' => null]);
        $this->at('2026-11-18 20:00');
        $this->sendLead('18922220000')->assertCreated();
        Http::assertNothingSent();
    }

    public function test_ntfy_failure_does_not_lose_the_lead(): void
    {
        Http::fake(['ntfy.test*' => Http::response('erro', 500)]);
        $this->at('2026-11-22 10:00'); // domingo
        $this->sendLead()->assertCreated();
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_alert_hours(): void
    {
        $hours = config('services.leads.alert.hours');
        $cases = [
            '2026-11-18 10:59' => false, '2026-11-18 11:00' => true, '2026-11-18 16:59' => true, '2026-11-18 17:00' => false,
            '2026-11-18 17:59' => false, '2026-11-18 18:00' => true, '2026-11-18 23:59' => true, '2026-11-19 04:59' => true,
            '2026-11-19 05:00' => false, '2026-11-21 04:00' => true, '2026-11-21 12:00' => false, '2026-11-21 13:59' => false,
            '2026-11-21 14:00' => true, '2026-11-22 09:00' => true, '2026-11-23 04:00' => true, '2026-11-23 06:00' => false,
        ];
        foreach ($cases as $time => $expected) {
            $this->assertSame($expected, LeadAlert::inWindow(Carbon::parse($time, 'America/Sao_Paulo'), $hours), $time);
        }
    }
}

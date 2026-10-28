<?php

/*
 * Processo filho dos testes de concorrência (tests/Feature/ConcurrencyTest.php).
 * Sobe a aplicação com conexão própria ao banco, avisa que está pronto, espera a largada (arquivo de
 * barreira) e executa uma operação. Responde uma linha JSON.
 */

use App\Models\BenefitRule;
use App\Models\BenefitUsage;
use App\Models\Proposal;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Domain\BenefitService;
use App\Services\Domain\DomainException;
use App\Services\Domain\ProposalService;
use App\Services\Integrations\VindiWebhookService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$task = json_decode($argv[1], true);
// Só o recebimento entra na disputa; o processamento do webhook (consulta à Vindi) fica fora.
Queue::fake();
// Mesmo disco falso do teste (BuildsActiveSubscription), sem apagar o contrato que ele gravou.
Storage::persistentFake('local');
DB::connection()->getPdo();

fwrite(STDOUT, "ready\n");
fflush(STDOUT);
while (! file_exists($task['barrier'])) {
    usleep(200);
}

try {
    $user = isset($task['user_id']) ? User::findOrFail($task['user_id']) : null;
    $result = match ($task['op']) {
        'reserve' => ['usage_id' => app(BenefitService::class)->reserve(
            Subscription::findOrFail($task['subscription_id']), BenefitRule::findOrFail($task['rule_id']), $user, $task['franchise_id'], 1, $task['key'],
        )->id],
        'reverse' => ['usage_id' => app(BenefitService::class)->reverse(BenefitUsage::findOrFail($task['usage_id']), $user, 1, 'estorno em paralelo')->id],
        'accept' => ['subscription_id' => app(ProposalService::class)->accept(Proposal::findOrFail($task['proposal_id']), $user, ['accepted' => true])->id],
        'webhook' => ['event_id' => app(VindiWebhookService::class)->receive(Request::create('/webhooks/vindi', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Basic '.base64_encode($task['credentials'])], $task['body']))->id],
    };
    echo json_encode(['ok' => true] + $result), "\n";
} catch (DomainException $e) {
    echo json_encode(['ok' => false, 'error' => DomainException::class, 'code' => $e->errorCode]), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 300)]), "\n";
}

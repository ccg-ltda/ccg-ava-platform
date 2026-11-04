<?php

/*
 * One competing operation of tests/Feature/N8nConcurrencyTest.php, run as its OWN PHP process with its own database connection (a real race, not a simulation inside one
 * transaction). Usage: php n8n-race-worker.php <kind> <json params> <barrier file>
 * Kinds: handoff | take | message | step | booking | survey. It waits for the barrier file so every worker starts together, runs the REAL service the API uses and prints ONE
 * json line. It refuses to run unless the database is a disposable PostgreSQL one (name ending in _testing).
 */

use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityStepReporter;
use App\Services\BookingRecords;
use App\Services\ConversationControl;
use App\Services\ConversationService;
use App\Services\SurveyRecorder;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.'.config('database.default').'.database');

if (config('database.default') !== 'pgsql' || ! str_ends_with($database, '_testing')) {
    fwrite(STDERR, "Refusing to run: [{$database}] is not a disposable PostgreSQL database (_testing).\n");
    exit(2);
}

[, $kind, $json, $barrier] = $argv + array_fill(0, 4, null);
$p = json_decode((string) $json, true);
$deadline = microtime(true) + 20;

while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        echo json_encode(['ok' => false, 'error' => 'barrier-timeout'])."\n";
        exit(0);
    }

    usleep(200);
}

try {
    $workspace = Workspace::findOrFail($p['workspace']);
    $chatbot = Chatbot::findOrFail($p['chatbot']);

    $result = match ($kind) {
        'handoff' => ['ok' => true, 'handling' => app(ConversationControl::class)->requestHuman(Conversation::findOrFail($p['conversation']), 'race')->handling],
        'take' => ['ok' => true, 'handling' => app(ConversationControl::class)->take(Conversation::findOrFail($p['conversation']), User::findOrFail($p['user']))->handling],
        'message' => ['ok' => true, 'created' => app(ConversationService::class)->record($chatbot, 'whatsapp', ['contact_id' => '573001112233', 'direction' => 'in', 'type' => 'text', 'body' => 'Hola', 'external_id' => 'wamid.RACE', 'sent_at' => now()])['created']],
        'step' => ['ok' => true, 'created' => app(ActivityStepReporter::class)->report($chatbot, Conversation::findOrFail($p['conversation']), ['step_ref' => 'race:step:0', 'engine' => 'n8n', 'tool' => 'race.tool', 'status' => 'succeeded', 'http_status' => 200])['created']],
        'booking' => ['ok' => true, 'created' => app(BookingRecords::class)->report($workspace, ['source' => 'booker', 'external_id' => 'RACE-1', 'status' => 'booked', 'starts_at' => '2026-12-01T15:00:00Z'], 'n8n')['created']],
        'survey' => ['ok' => true, 'created' => app(SurveyRecorder::class)->respond($workspace, ['survey_key' => 'race', 'external_ref' => 'race-response', 'rating' => 4], 'n8n')['created']],
        default => ['ok' => false, 'error' => 'unknown kind'],
    };

    echo json_encode($result)."\n";
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'error' => $exception::class.': '.mb_substr($exception->getMessage(), 0, 140)])."\n";
}

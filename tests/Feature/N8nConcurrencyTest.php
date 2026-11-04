<?php

namespace Tests\Feature;

use App\Models\AgentToolCall;
use App\Models\BookingRecord;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The operations n8n repeats or races, run as SEPARATE PHP processes against a disposable PostgreSQL database (name ending in `_testing`): a handoff asked eight times, two
 * agents taking the same conversation, the same message / step / reservation / survey answer reported by eight retries. Each must leave exactly ONE effect. SQLite can not
 * show this (one connection), so the test is skipped there, like BookingRaceTest. See tests/Support/n8n-race-worker.php.
 */
class N8nConcurrencyTest extends TestCase
{
    use BuildsBookings;
    use DatabaseMigrations;

    private Workspace $workspace;

    private Chatbot $bot;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The race needs PostgreSQL (separate processes, one disposable *_testing database).');
        }

        $this->seedRoleCatalog();
        $this->workspace = $this->bookingWorkspace('RACE', ['dashboard', 'assistants', 'conversations', 'reports']);
        $this->bot = $this->workspace->chatbots()->create(['name' => 'Bot']);
        $this->bot->channels()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'is_active' => true]);
        $this->conversation = $this->bot->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573001112233', 'contact_name' => 'Laura'])->refresh();
    }

    /**
     * @param  list<array<string, mixed>>  $jobs  one [kind, params] per process
     * @return list<array<string, mixed>>
     */
    private function race(array $jobs): array
    {
        $barrier = sys_get_temp_dir().'/n8n-race-'.bin2hex(random_bytes(6));
        $processes = [];

        foreach ($jobs as [$kind, $params]) {
            $params += ['workspace' => $this->workspace->id, 'chatbot' => $this->bot->id, 'conversation' => $this->conversation->id];
            $process = proc_open([PHP_BINARY, base_path('tests/Support/n8n-race-worker.php'), $kind, json_encode($params), $barrier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        usleep(2_000_000); // every worker boots and waits at the barrier
        touch($barrier);

        $results = [];

        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $decoded = json_decode(trim((string) collect(explode("\n", trim($output)))->last()), true);
            $this->assertIsArray($decoded, "worker output: {$output} / {$errors}");
            $results[] = $decoded;
        }

        @unlink($barrier);

        return $results;
    }

    public function test_eight_simultaneous_handoff_requests_move_the_conversation_once(): void
    {
        $before = $this->conversation->handling_version;

        $results = $this->race(array_fill(0, 8, ['handoff', []]));

        $this->assertSame(8, collect($results)->where('ok', true)->count(), json_encode($results));
        $conversation = $this->conversation->refresh();
        $this->assertSame('pending', $conversation->handling);
        $this->assertSame($before + 1, $conversation->handling_version, 'The state changed exactly once: the other seven were no-ops.');
    }

    public function test_two_agents_taking_the_same_pending_conversation_leave_one_owner(): void
    {
        $this->race([['handoff', []]]);
        $a = $this->member($this->workspace, 'admin');
        $b = $this->member($this->workspace, 'admin');

        $results = $this->race([['take', ['user' => $a->id]], ['take', ['user' => $b->id]], ['take', ['user' => $a->id]], ['take', ['user' => $b->id]]]);

        $conversation = $this->conversation->refresh();
        $this->assertSame('human', $conversation->handling);
        $this->assertContains($conversation->assigned_user_id, [$a->id, $b->id]);
        $this->assertGreaterThanOrEqual(1, collect($results)->where('ok', true)->count(), json_encode($results));
        $this->assertSame(0, collect($results)->filter(fn ($r) => ! $r['ok'] && ! str_contains($r['error'], 'Conversation'))->count(), 'Losers get a controlled refusal, never a crash: '.json_encode($results));
    }

    public function test_the_same_inbound_message_reported_eight_times_is_stored_once(): void
    {
        $results = $this->race(array_fill(0, 8, ['message', []]));

        $this->assertSame(8, collect($results)->where('ok', true)->count(), json_encode($results));
        $this->assertSame(1, collect($results)->where('created', true)->count(), 'Only one retry created it: '.json_encode($results));
        $this->assertSame(1, Message::where('external_id', 'wamid.RACE')->count());
    }

    public function test_the_same_technical_step_reported_eight_times_is_one_step(): void
    {
        $results = $this->race(array_fill(0, 8, ['step', []]));

        $this->assertSame(8, collect($results)->where('ok', true)->count(), json_encode($results));
        $this->assertSame(1, AgentToolCall::where('external_ref', 'race:step:0')->count());
    }

    public function test_the_same_reservation_reported_eight_times_is_one_record(): void
    {
        $results = $this->race(array_fill(0, 8, ['booking', []]));

        $this->assertSame(8, collect($results)->where('ok', true)->count(), json_encode($results));
        $this->assertSame(1, BookingRecord::where('external_id', 'RACE-1')->count());
    }

    public function test_the_same_survey_answer_reported_eight_times_is_one_answer(): void
    {
        Survey::create(['workspace_id' => $this->workspace->id, 'key' => 'race', 'name' => 'Carrera', 'scale_min' => 1, 'scale_max' => 5]);

        $results = $this->race(array_fill(0, 8, ['survey', []]));

        $this->assertSame(8, collect($results)->where('ok', true)->count(), json_encode($results));
        $this->assertSame(1, SurveyResponse::where('external_ref', 'race-response')->count());
    }
}

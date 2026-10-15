<?php

namespace Tests\Feature;

use App\Booking\BookingManager;
use App\Booking\ProviderException;
use App\Integrations\SafeHttpTarget;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Service;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Services\ChatbotExecutions;
use App\Services\ChatbotWorkflows;
use App\Services\ExecutionAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\BuildsBookings;
use Tests\Support\SimulatedCalendar;
use Tests\TestCase;

/**
 * How the workflow Ava starts reaches Ava's tools (a run-scoped token), the web channel running on the same execution path as WhatsApp,
 * and the diagnostics of executions. n8n and Meta are SIMULATED: nothing here proves a real connection.
 */
class PlatformRunTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const N8N = 'https://n8n.platform.example.com/webhook';

    private const MODULES = ['dashboard', 'assistants', 'conversations', 'service_catalog', 'professionals', 'bookings'];

    private Workspace $workspace;

    private Chatbot $bot;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        config(['n8n.execution_url' => self::N8N, 'n8n.secret' => 'run-secret', 'n8n.backoff' => [0, 0], 'n8n.workflows.support' => ['name' => 'Soporte', 'n8n_workflow_id' => 'wf1', 'webhook_path' => 'support-v1', 'version' => '1']]);
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);

        $this->workspace = $this->bookingWorkspace('SALON', self::MODULES);
        $this->service = $this->makeService($this->workspace);
        $this->makeProfessional($this->workspace, [$this->service]);
        $this->bot = $this->workspace->chatbots()->create(['name' => 'Asistente', 'instructions' => 'Atiende reservas']);
        app(ChatbotWorkflows::class)->assign($this->bot->refresh(), 'support');
    }

    private function conversation(?Chatbot $bot = null, string $channel = 'whatsapp', string $contact = '573001112233'): Conversation
    {
        $bot ??= $this->bot;

        return $bot->conversations()->create(['workspace_id' => $bot->workspace_id, 'channel' => $channel, 'contact_id' => $contact])->refresh();
    }

    /** An execution in the state it has while the workflow runs, and the token Ava gives that workflow. @return array{0: ChatbotExecution, 1: string} */
    private function running(Conversation $conversation): array
    {
        $message = $conversation->messages()->create(['workspace_id' => $conversation->workspace_id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Quiero una cita', 'external_id' => 'wamid.'.Str::random(8), 'sent_at' => now()]);
        $execution = ChatbotExecution::create([
            'correlation_id' => (string) Str::uuid(), 'workspace_id' => $conversation->workspace_id, 'chatbot_id' => $conversation->chatbot_id, 'conversation_id' => $conversation->id,
            'message_id' => $message->id, 'workflow_key' => 'support', 'n8n_workflow_id' => 'wf1', 'handling_version' => 0, 'status' => ChatbotExecution::RUNNING, 'attempts' => 1,
        ]);

        return [$execution, app(ExecutionAccess::class)->issue($execution)];
    }

    private function book(array $overrides = []): array
    {
        return $overrides + ['service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura Pérez', 'phone' => '+573001112233']];
    }

    private function tool(string $method, string $path, string $token, array $data = [])
    {
        return $this->withToken($token)->json($method, "/api/agent{$path}", $data);
    }

    // --- what the workflow receives -----------------------------------------------------------------------------------

    public function test_the_workflow_receives_a_run_scoped_token_and_the_tools_of_its_plan_but_no_long_lived_secret(): void
    {
        $long = app(AgentAccess::class)->generate($this->bot);
        Http::fake([self::N8N.'/*' => fn (Request $r) => Http::response(['execution_id' => $r->header('X-Ava-Execution')[0], 'reply' => 'Hola'])]);
        $conversation = $this->conversation();
        $message = $conversation->messages()->create(['workspace_id' => $this->workspace->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Hola', 'external_id' => 'wamid.X1', 'sent_at' => now()]);

        app(ChatbotExecutions::class)->schedule($conversation->load('chatbot.workspace.organization'), $message);

        $body = json_decode(Http::recorded(fn (Request $r) => str_starts_with($r->url(), self::N8N))->first()[0]->body(), true);
        $this->assertStringStartsWith('avx_', $body['ava']['token']);
        $this->assertSame(url('/api/agent'), $body['ava']['api_url']);
        $this->assertTrue($body['ava']['tools']['booking']);
        $this->assertStringNotContainsString($long, json_encode($body), 'The long-lived chatbot token never travels to the workflow.');
        $this->assertStringNotContainsString('573001112233', json_encode($body));
    }

    public function test_the_booking_tools_are_not_announced_when_the_plan_lacks_them(): void
    {
        $this->contractModules($this->workspace, ['dashboard', 'assistants', 'conversations']);
        Http::fake([self::N8N.'/*' => fn (Request $r) => Http::response(['execution_id' => $r->header('X-Ava-Execution')[0], 'reply' => 'Hola'])]);
        $conversation = $this->conversation();
        $message = $conversation->messages()->create(['workspace_id' => $this->workspace->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Hola', 'external_id' => 'wamid.X1', 'sent_at' => now()]);

        app(ChatbotExecutions::class)->schedule($conversation->load('chatbot.workspace.organization'), $message);

        $body = json_decode(Http::recorded(fn (Request $r) => str_starts_with($r->url(), self::N8N))->first()[0]->body(), true);
        $this->assertFalse($body['ava']['tools']['booking']);
    }

    // --- what the run-scoped token may do -------------------------------------------------------------------------------

    public function test_the_token_books_for_the_conversation_of_its_execution_without_naming_the_contact(): void
    {
        $conversation = $this->conversation();
        [, $token] = $this->running($conversation);

        $this->tool('GET', '/booking/services', $token)->assertOk()->assertJsonPath('services.0.name', 'Manicure');
        $this->tool('POST', '/booking/appointments', $token, $this->book())->assertCreated()->assertJsonPath('confirmed', true);

        $this->assertSame($conversation->id, Appointment::sole()->conversation_id);
        $this->tool('GET', '/booking/appointments', $token)->assertOk()->assertJsonCount(1, 'appointments');
    }

    public function test_the_token_cannot_act_for_another_contact_or_conversation(): void
    {
        [, $token] = $this->running($this->conversation());
        $this->conversation(null, 'whatsapp', '573009998877');

        $this->tool('POST', '/booking/appointments', $token, $this->book(['channel' => 'whatsapp', 'contact_id' => '573009998877']))->assertForbidden();
        $this->tool('GET', '/booking/appointments?channel=web&contact_id=abc', $token)->assertForbidden();
        $this->assertSame(0, Appointment::count());
    }

    public function test_the_token_reaches_only_the_booking_tools(): void
    {
        [, $token] = $this->running($this->conversation());

        foreach ([['GET', '/config'], ['POST', '/messages'], ['POST', '/messages/status'], ['POST', '/conversations/authorize'], ['POST', '/conversations/handoff']] as [$method, $path]) {
            $this->tool($method, $path, $token, ['x' => 1])->assertForbidden();
        }
    }

    public function test_the_token_dies_when_its_execution_ends_expires_or_is_forged(): void
    {
        [$execution, $token] = $this->running($this->conversation());
        $this->tool('GET', '/booking/services', $token)->assertOk();

        $this->tool('GET', '/booking/services', substr($token, 0, -1).(str_ends_with($token, 'a') ? 'b' : 'a'))->assertUnauthorized();
        $this->tool('GET', '/booking/services', 'avx_1.2.'.str_repeat('a', 40))->assertUnauthorized();
        $this->tool('GET', '/booking/services', 'avx_garbage')->assertUnauthorized();

        $execution->update(['status' => ChatbotExecution::SUCCEEDED]);
        $this->tool('GET', '/booking/services', $token)->assertUnauthorized();

        $execution->update(['status' => ChatbotExecution::RUNNING]);
        $this->tool('GET', '/booking/services', $token)->assertOk();
        $this->travel(config('n8n.run_token_ttl') + 5)->seconds();
        $this->tool('GET', '/booking/services', $token)->assertUnauthorized();
    }

    public function test_the_token_stops_working_when_the_chatbot_or_workspace_is_switched_off_or_the_plan_loses_the_modules(): void
    {
        [, $token] = $this->running($this->conversation());

        $this->bot->update(['is_active' => false]);
        $this->tool('GET', '/booking/services', $token)->assertForbidden();
        $this->bot->update(['is_active' => true]);

        $this->contractModules($this->workspace, ['dashboard', 'assistants', 'conversations']);
        $this->tool('GET', '/booking/services', $token)->assertForbidden();
    }

    public function test_a_token_of_one_workspace_never_reaches_the_agenda_of_another(): void
    {
        $other = $this->bookingWorkspace('OTRO', self::MODULES);
        $otherService = $this->makeService($other, ['name' => 'Ajeno']);
        $this->makeProfessional($other, [$otherService]);
        [, $token] = $this->running($this->conversation());

        $this->tool('GET', '/booking/services', $token)->assertOk()->assertJsonMissing(['name' => 'Ajeno']);
        $this->tool('POST', '/booking/appointments', $token, $this->book(['service_id' => $otherService->id]))->assertStatus(422);
        $this->assertSame(0, Appointment::count());
    }

    // --- the token follows control, leaves no trace and cannot reach other appointments ---------------------------------------------

    public function test_the_token_dies_the_moment_a_person_takes_asks_for_or_closes_the_conversation(): void
    {
        foreach (['human', 'pending', 'resolved'] as $handling) {
            $conversation = $this->conversation(null, 'whatsapp', '5730000'.random_int(1000, 9999));
            [, $token] = $this->running($conversation);
            $this->tool('GET', '/booking/services', $token)->assertOk();

            $conversation->forceFill(['handling' => $handling, 'handling_version' => $conversation->handling_version + 1])->save();

            $this->tool('GET', '/booking/services', $token)->assertUnauthorized();
            $this->tool('POST', '/booking/appointments', $token, $this->book())->assertUnauthorized();
        }

        $this->assertSame(0, Appointment::count());
    }

    public function test_the_token_cannot_touch_the_appointments_of_another_conversation_contact_or_workspace(): void
    {
        $mine = $this->conversation();
        [, $token] = $this->running($mine);
        $other = $this->conversation(null, 'whatsapp', '573009998877');
        [, $otherToken] = $this->running($other);
        $theirs = $this->tool('POST', '/booking/appointments', $otherToken, $this->book(['starts_at' => '2026-10-14T10:00:00Z']))->assertCreated()->json('appointment.id');

        $this->tool('POST', "/booking/appointments/{$theirs}/cancel", $token)->assertNotFound();
        $this->tool('POST', "/booking/appointments/{$theirs}/reschedule", $token, ['starts_at' => '2026-10-15T10:00:00Z'])->assertNotFound();
        $this->tool('GET', '/booking/appointments', $token)->assertOk()->assertJsonCount(0, 'appointments');
        $this->assertSame('confirmed', Appointment::find($theirs)->status);

        $foreign = $this->bookingWorkspace('OTRO', self::MODULES);
        $foreignBot = $foreign->chatbots()->create(['name' => 'Otro']);
        [, $foreignToken] = $this->running($this->conversation($foreignBot, 'whatsapp', '573001112233'));
        $this->tool('POST', "/booking/appointments/{$theirs}/cancel", $foreignToken)->assertNotFound();
    }

    public function test_the_token_never_appears_in_responses_logs_audit_executions_or_failed_jobs(): void
    {
        $logs = [];
        Log::listen(function ($event) use (&$logs) {
            $logs[] = $event->message.json_encode($event->context);
        });
        $captured = null;
        Http::fake([self::N8N.'/*' => function (Request $request) use (&$captured) {
            $captured = json_decode($request->body(), true)['ava']['token'];

            return Http::response('boom '.$captured, 500); // a workflow that errors and echoes what it received
        }]);
        config(['n8n.backoff' => [0, 0]]);
        $conversation = $this->conversation();
        $message = $conversation->messages()->create(['workspace_id' => $this->workspace->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Hola', 'external_id' => 'wamid.LEAK', 'sent_at' => now()]);

        app(ChatbotExecutions::class)->schedule($conversation->load('chatbot.workspace.organization'), $message);

        $this->assertNotNull($captured);
        $dump = json_encode([ChatbotExecution::all()->toArray(), DB::table('failed_jobs')->get()->toArray(), AuditLog::all()->toArray(), $conversation->messages()->get()->toArray(), $logs]);
        $this->assertStringNotContainsString($captured, $dump);
        $this->assertStringNotContainsString(substr($captured, 4, 20), $dump);

        $answer = $this->tool('GET', '/booking/services', $captured)->assertUnauthorized(); // the run ended: the token is already dead
        $this->assertStringNotContainsString($captured, $answer->getContent());
        $this->assertStringNotContainsString($captured, $this->tool('GET', '/config', $captured)->getContent());
    }

    // --- retries and uncertain answers never duplicate a booking ------------------------------------------------------------------------

    public function test_the_same_booking_asked_twice_returns_the_one_that_exists(): void
    {
        [, $token] = $this->running($this->conversation());

        $first = $this->tool('POST', '/booking/appointments', $token, $this->book())->assertCreated()->assertJsonPath('replayed', false);
        $again = $this->tool('POST', '/booking/appointments', $token, $this->book())->assertOk()->assertJsonPath('replayed', true);

        $this->assertSame($first->json('appointment.id'), $again->json('appointment.id'));
        $this->assertSame(1, Appointment::count());

        // A different moment is a different booking; after cancelling, the same moment can be booked again.
        $this->tool('POST', '/booking/appointments', $token, $this->book(['starts_at' => '2026-10-14T10:00:00Z']))->assertCreated();
        $this->tool('POST', '/booking/appointments/'.$first->json('appointment.id').'/cancel', $token)->assertOk();
        $this->tool('POST', '/booking/appointments', $token, $this->book())->assertCreated()->assertJsonPath('replayed', false);
        $this->assertSame(3, Appointment::count());
    }

    private function externalBody(): array
    {
        return ['service_id' => 'S1', 'professional_id' => 'P1', 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura', 'phone' => '573001112233']];
    }

    public function test_a_lost_answer_from_an_external_agenda_is_uncertain_and_is_never_written_twice(): void
    {
        $simulated = new SimulatedCalendar;
        $simulated->failWith['book'] = 'timeout';
        $this->useSimulatedAgenda($simulated);
        $this->useBookerSetting($this->workspace);
        [, $token] = $this->running($this->conversation());

        $first = $this->tool('POST', '/booking/appointments', $token, $this->externalBody())->assertStatus(409);
        $this->assertSame('uncertain', $first->json('reason'));
        $appointment = Appointment::sole();
        $this->assertSame(['requested', 'uncertain', 'timeout'], [$appointment->status, $appointment->sync_status, $appointment->sync_error]);

        // The workflow retries (the agenda may even have booked it): Ava does NOT write again until somebody verifies.
        $simulated->failWith = [];
        $this->tool('POST', '/booking/appointments', $token, $this->externalBody())->assertStatus(409)->assertJsonPath('reason', 'uncertain')->assertJsonPath('appointment_id', $appointment->id);
        $this->assertSame(1, count(array_keys($simulated->calls, 'book', true)), 'only the first attempt reached the agenda for writing');
        $this->assertSame(1, Appointment::count());
    }

    public function test_staff_can_close_an_uncertain_request_after_checking_the_external_agenda(): void
    {
        $simulated = new SimulatedCalendar;
        $simulated->failWith['book'] = 'unavailable';
        $this->useSimulatedAgenda($simulated);
        $this->useBookerSetting($this->workspace);
        [, $token] = $this->running($this->conversation());
        $this->tool('POST', '/booking/appointments', $token, $this->externalBody())->assertStatus(409);
        $appointment = Appointment::sole();

        app(BookingManager::class)->cancel($appointment, null, 'admin');

        $this->assertSame(['cancelled', 'failed', 'not_found'], [$appointment->fresh()->status, $appointment->fresh()->sync_status, $appointment->fresh()->sync_error]);
        // Once closed, the same request can be made again.
        $simulated->failWith = [];
        $this->tool('POST', '/booking/appointments', $token, $this->externalBody())->assertCreated();
    }

    public function test_only_answers_that_may_have_been_processed_count_as_uncertain(): void
    {
        foreach (['auth', 'invalid', 'rate_limited', 'conflict', 'unsupported'] as $code) {
            $this->assertFalse((new ProviderException($code))->uncertain(), $code);
        }
        foreach (['timeout', 'unavailable', 'unknown'] as $code) {
            $this->assertTrue((new ProviderException($code))->uncertain(), $code);
        }
    }

    // --- the web channel runs on the same path as WhatsApp ----------------------------------------------------------------------

    private function webWidget(Chatbot $bot): string
    {
        $integration = $bot->workspace->integrations()->create(['name' => 'Web', 'type' => 'web', 'is_active' => true, 'config' => ['webhook_url' => 'https://n8n.example.com/webhook/ava-web', 'allowed_origins' => []]]);

        return $bot->channels()->create(['workspace_id' => $bot->workspace_id, 'channel' => 'web', 'integration_id' => $integration->id, 'is_active' => true, 'public_key' => Str::lower(Str::random(32))])->public_key;
    }

    public function test_a_web_visitor_is_answered_by_the_platform_workflow_and_collects_the_reply(): void
    {
        $key = $this->webWidget($this->bot);
        Http::fake([self::N8N.'/*' => fn (Request $r) => Http::response(['execution_id' => $r->header('X-Ava-Execution')[0], 'reply' => 'Claro, ¿qué día prefieres?'])]);
        $session = 'visitor-session-1234';

        $this->postJson("/api/widget/{$key}/messages", ['session_id' => $session, 'message' => 'Quiero una cita'])
            ->assertOk()->assertJsonPath('reply', null)->assertJsonPath('pending', true);

        $execution = ChatbotExecution::sole();
        $this->assertSame('succeeded', $execution->status);
        $this->assertSame('web', $execution->conversation->channel);

        $this->getJson("/api/widget/{$key}/messages?session_id={$session}")->assertOk()
            ->assertJsonPath('messages.0.text', 'Claro, ¿qué día prefieres?')->assertJsonPath('messages.0.sender', 'ai');
        $this->getJson("/api/widget/{$key}/messages?session_id={$session}")->assertOk()->assertJsonCount(0, 'messages');
        $this->assertSame('delivered', $execution->conversation->messages()->where('direction', 'out')->first()->status);
    }

    public function test_the_widget_is_told_whether_an_answer_is_still_being_prepared(): void
    {
        $key = $this->webWidget($this->bot);
        $session = 'visitor-session-9999';

        // A workflow that rejects the request: the execution ends failed, nothing was answered, and the widget is told to stop waiting.
        Http::fake([self::N8N.'/*' => Http::response('boom', 400)]);
        $this->postJson("/api/widget/{$key}/messages", ['session_id' => $session, 'message' => 'Hola'])->assertOk()->assertJsonPath('pending', true);
        $this->assertSame('failed', ChatbotExecution::sole()->status);
        $this->getJson("/api/widget/{$key}/messages?session_id={$session}")->assertOk()->assertJsonPath('answering', false)->assertJsonCount(0, 'messages');

        // An execution still queued or running: keep waiting.
        ChatbotExecution::query()->update(['status' => ChatbotExecution::RUNNING]);
        $this->getJson("/api/widget/{$key}/messages?session_id={$session}")->assertOk()->assertJsonPath('answering', true);

        // Another visitor of the same widget, and a session nobody used, are not told anything about this one.
        $this->getJson("/api/widget/{$key}/messages?session_id=visitor-session-0000")->assertOk()->assertJsonPath('answering', false)->assertJsonCount(0, 'messages');
    }

    public function test_one_visitor_never_collects_another_visitors_answer_or_another_widgets(): void
    {
        $key = $this->webWidget($this->bot);
        Http::fake([self::N8N.'/*' => fn (Request $r) => Http::response(['execution_id' => $r->header('X-Ava-Execution')[0], 'reply' => 'Solo para ti'])]);
        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'visitor-session-1111', 'message' => 'Hola'])->assertOk();

        $this->getJson("/api/widget/{$key}/messages?session_id=visitor-session-2222")->assertOk()->assertJsonCount(0, 'messages');

        $other = $this->workspace2Widget();
        $this->getJson("/api/widget/{$other}/messages?session_id=visitor-session-1111")->assertOk()->assertJsonCount(0, 'messages');

        $this->getJson("/api/widget/{$key}/messages?session_id=visitor-session-1111")->assertOk()->assertJsonPath('messages.0.text', 'Solo para ti');
    }

    private function workspace2Widget(): string
    {
        $ws = $this->bookingWorkspace('OTRO2', self::MODULES);

        return $this->webWidget($ws->chatbots()->create(['name' => 'Otro']));
    }

    public function test_a_web_conversation_taken_by_a_person_runs_no_workflow(): void
    {
        $key = $this->webWidget($this->bot);
        Http::fake();
        $conversation = $this->conversation(null, 'web', 'visitor-session-1234');
        $conversation->forceFill(['handling' => Conversation::HUMAN])->save();

        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'visitor-session-1234', 'message' => 'Hola'])->assertOk()->assertJsonPath('reply', null)->assertJsonMissingPath('pending');

        $this->assertSame(0, ChatbotExecution::count());
        Http::assertNothingSent();
    }

    public function test_without_a_platform_workflow_the_web_widget_keeps_using_the_workspace_webhook_and_answers_in_the_response(): void
    {
        $bot = $this->workspace->chatbots()->create(['name' => 'Sin workflow']);
        $key = $this->webWidget($bot);
        Http::fake(['https://n8n.example.com/*' => Http::response(['reply' => 'Hola desde el webhook'])]);

        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'visitor-session-5678', 'message' => 'Hola'])->assertOk()->assertJsonPath('reply', 'Hola desde el webhook')->assertJsonMissingPath('pending');

        $this->assertSame(0, ChatbotExecution::count());
        $this->getJson("/api/widget/{$key}/messages?session_id=visitor-session-5678")->assertOk()->assertJsonCount(0, 'messages');
    }

    // --- diagnosing executions without opening the database -----------------------------------------------------------------

    public function test_failed_executions_are_listed_and_explained_without_any_conversation_content(): void
    {
        $conversation = $this->conversation();
        [$execution] = $this->running($conversation);
        $execution->update(['status' => ChatbotExecution::FAILED, 'outcome_code' => 'invalid_response', 'finished_at' => now()]);

        Artisan::call('executions:list', ['--failed' => true]);
        $list = Artisan::output();
        $this->assertStringContainsString('invalid_response', $list);
        $this->assertStringContainsString((string) $execution->id, $list);

        Artisan::call('executions:list', ['--show' => $execution->id]);
        $detail = Artisan::output();
        $this->assertStringContainsString('execution_id', $detail);
        $this->assertStringContainsString('Significado:', $detail);

        foreach ([$list, $detail] as $text) {
            foreach (['Quiero una cita', '573001112233', 'Atiende reservas', 'run-secret'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $text);
            }
        }
        $this->assertSame(1, Artisan::call('executions:list', ['--show' => 999999]));
    }

    public function test_the_list_can_be_limited_to_one_workspace(): void
    {
        [$mine] = $this->running($this->conversation());
        $other = $this->bookingWorkspace('OTRO', self::MODULES);
        $otherBot = $other->chatbots()->create(['name' => 'Otro']);
        [$theirs] = $this->running($this->conversation($otherBot));

        Artisan::call('executions:list', ['--workspace' => $this->workspace->id, '--limit' => 50]);
        $out = Artisan::output();

        $this->assertMatchesRegularExpression("/\|\s+{$mine->id}\s+\|/", $out);
        $this->assertDoesNotMatchRegularExpression("/\|\s+{$theirs->id}\s+\|/", $out);
    }
}

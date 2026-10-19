<?php

namespace Tests\Feature;

use App\Integrations\SafeHttpTarget;
use App\Models\Chatbot;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\AgentAccess;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Tests\TestCase;

/**
 * What a real first integration (Meta WhatsApp webhook + the platform n8n) needs from Ava before any credential exists:
 * delivery states that only move forward, a webhook that refuses what it cannot trust, and a diagnostic that reports
 * readiness without exposing a secret or a conversation. Meta and n8n are SIMULATED here; nothing proves a real connection.
 */
class IntegrationReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'meta-app-SECRET-456';

    private const TOKEN = 'EAAB-private-token-XYZ';

    private const N8N_SECRET = 'n8n-shared-SECRET-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        config(['services.meta.app_secret' => self::APP_SECRET, 'services.meta.webhook_verify_token' => 'verify-me', 'n8n.secret' => self::N8N_SECRET, 'n8n.execution_url' => null]);
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function workspace(string $code): Workspace
    {
        return Workspace::create(['organization_id' => (Organization::first() ?? Organization::create(['name' => 'Org']))->id, 'code' => $code, 'name' => "Workspace {$code}"]);
    }

    /** @return array{0: Chatbot, 1: string} */
    private function bot(Workspace $workspace, string $phone): array
    {
        $bot = $workspace->chatbots()->create(['name' => 'Asistente', 'instructions' => 'Instrucciones privadas']);
        $integration = $workspace->integrations()->create(['name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => $phone], 'secrets' => ['access_token' => self::TOKEN]]);
        $bot->channels()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);

        return [$bot, $phone];
    }

    private function deliver(array $payload, ?string $raw = null, ?string $signature = null)
    {
        $raw ??= json_encode($payload);

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature ?? 'sha256='.hash_hmac('sha256', $raw, self::APP_SECRET),
        ], $raw);
    }

    private function inbound(string $phone, string $id, string $text = 'Hola', string $from = '573001112233'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => $phone], 'contacts' => [['profile' => ['name' => 'Ana'], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]];
    }

    private function stateEvent(string $phone, string $id, string $state, string $to = '573001112233'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => $phone], 'statuses' => [['id' => $id, 'recipient_id' => $to, 'status' => $state]],
        ]]]]]];
    }

    private function outgoing(Chatbot $bot, string $externalId, ?string $status = 'sent'): Message
    {
        $conversation = $bot->conversations()->firstOrCreate(['channel' => 'whatsapp', 'contact_id' => '573001112233'], ['workspace_id' => $bot->workspace_id]);

        return $conversation->messages()->create(['workspace_id' => $bot->workspace_id, 'direction' => 'out', 'sender' => 'ai', 'type' => 'text', 'body' => 'Respuesta', 'external_id' => $externalId, 'status' => $status, 'sent_at' => now()]);
    }

    // --- delivery states only move forward ----------------------------------------------------------------------------

    public function test_delivery_states_from_meta_never_move_backwards_when_events_repeat_or_arrive_out_of_order(): void
    {
        [$bot, $phone] = $this->bot($this->workspace('WS_A'), '1000001');
        $message = $this->outgoing($bot, 'wamid.OUT1');

        foreach (['read', 'delivered', 'sent', 'delivered', 'failed'] as $late) {
            $this->deliver($this->stateEvent($phone, 'wamid.OUT1', $late))->assertOk();
            $this->assertSame('read', $message->refresh()->status, "A late [{$late}] must not undo [read].");
        }
    }

    public function test_a_delivery_goes_sent_delivered_read_in_order_and_a_failure_only_replaces_what_was_not_delivered(): void
    {
        [$bot, $phone] = $this->bot($this->workspace('WS_A'), '1000001');
        $message = $this->outgoing($bot, 'wamid.OUT1', Message::PENDING);

        foreach (['sent', 'delivered', 'read'] as $state) {
            $this->deliver($this->stateEvent($phone, 'wamid.OUT1', $state))->assertOk();
            $this->assertSame($state, $message->refresh()->status);
        }

        $failing = $this->outgoing($bot, 'wamid.OUT2', 'sent');
        $this->deliver($this->stateEvent($phone, 'wamid.OUT2', 'failed'))->assertOk();
        $this->assertSame('failed', $failing->refresh()->status);
    }

    public function test_a_state_reported_by_n8n_follows_the_same_order(): void
    {
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001');
        $message = $this->outgoing($bot, 'wamid.OUT1', 'delivered');
        $token = app(AgentAccess::class)->generate($bot);

        $this->withToken($token)->postJson('/api/agent/messages/status', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'external_id' => 'wamid.OUT1', 'status' => 'sent'])->assertOk();
        $this->assertSame('delivered', $message->refresh()->status);
    }

    public function test_a_status_of_another_workspace_number_never_touches_this_workspace_messages(): void
    {
        [$a] = $this->bot($this->workspace('WS_A'), '1000001');
        [, $phoneB] = $this->bot($this->workspace('WS_B'), '2000002');
        $message = $this->outgoing($a, 'wamid.OUT1', 'sent');

        // Number B reports a state for an id that only exists in Workspace A: nothing changes there.
        $this->deliver($this->stateEvent($phoneB, 'wamid.OUT1', 'read'))->assertOk();

        $this->assertSame('sent', $message->refresh()->status);
    }

    // --- the webhook refuses what it cannot trust --------------------------------------------------------------------------

    public function test_the_webhook_rejects_oversized_bodies_other_algorithms_and_tampered_bodies(): void
    {
        [, $phone] = $this->bot($this->workspace('WS_A'), '1000001');
        $payload = $this->inbound($phone, 'wamid.IN1');
        $raw = json_encode($payload);

        $this->deliver($payload, str_repeat('x', 1_000_001))->assertStatus(413);
        $this->deliver($payload, $raw, 'sha1='.hash_hmac('sha1', $raw, self::APP_SECRET))->assertForbidden();
        $this->deliver($payload, $raw, hash_hmac('sha256', $raw, self::APP_SECRET))->assertForbidden(); // no `sha256=` prefix
        $this->deliver($payload, $raw, 'sha256='.hash_hmac('sha256', $raw.' ', self::APP_SECRET))->assertForbidden(); // signed another body
        $this->deliver($payload, $raw, '')->assertForbidden();

        $this->assertSame(0, Message::count());
    }

    public function test_a_replayed_valid_event_is_stored_once_and_the_workspace_never_comes_from_the_payload(): void
    {
        [$a, $phone] = $this->bot($this->workspace('WS_A'), '1000001');
        $other = $this->workspace('WS_B');
        $payload = $this->inbound($phone, 'wamid.IN1');
        $payload['entry'][0]['changes'][0]['value']['workspace_id'] = $other->id; // a hostile hint: ignored

        for ($i = 0; $i < 3; $i++) {
            $this->deliver($payload)->assertOk();
        }

        $this->assertSame(1, Message::count());
        $this->assertSame($a->workspace_id, Message::first()->workspace_id);
        $this->assertSame(0, $other->chatbots()->count());
    }

    // --- diagnostics: readiness without secrets or conversations ---------------------------------------------------------------

    private function diagnose(array $options = []): array
    {
        $code = Artisan::call('integrations:diagnose', $options);

        return [$code, Artisan::output()];
    }

    public function test_the_diagnostic_reports_what_is_missing_and_blocks_on_a_conflict(): void
    {
        config(['services.meta.app_secret' => null, 'services.meta.webhook_verify_token' => null, 'n8n.secret' => null]);
        [, $output] = $this->diagnose();

        $this->assertStringContainsString('META_APP_SECRET y META_WEBHOOK_VERIFY_TOKEN', $output);
        $this->assertStringContainsString('N8N_EXECUTION_URL y N8N_EXECUTION_SECRET', $output);
        $this->assertStringContainsString('/api/webhooks/whatsapp', $output);

        $this->bot($this->workspace('WS_A'), '1000001');
        $this->bot($this->workspace('WS_B'), '1000001'); // two live integrations claim one number
        [$code, $output] = $this->diagnose();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('reclaman el mismo número', $output);
        $this->assertStringNotContainsString('1000001', $output, 'The number is never printed.');
    }

    public function test_the_diagnostic_never_prints_a_secret_a_name_a_contact_or_a_message(): void
    {
        [$bot, $phone] = $this->bot($this->workspace('WS_A'), '1000001');
        $this->deliver($this->inbound($phone, 'wamid.IN1', 'Mi tarjeta es 4111111111111111', '573009998877'))->assertOk();
        config(['n8n.execution_url' => 'https://n8n.platform.example.com/webhook']);
        [, $output] = $this->diagnose();
        [, $scoped] = $this->diagnose(['--workspace' => $bot->workspace_id]);

        foreach ([$output, $scoped] as $text) {
            foreach ([self::APP_SECRET, self::TOKEN, self::N8N_SECRET, 'verify-me', 'Instrucciones privadas', 'Mi tarjeta', '4111', '573009998877', 'Ana', 'n8n.platform.example.com'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $text, "The diagnostic leaked [{$forbidden}].");
            }
        }
        $this->assertStringContainsString('Canales WhatsApp activos: 1', $output);
    }

    public function test_the_diagnostic_flags_channels_that_will_not_receive_messages_and_stuck_executions(): void
    {
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001');
        $bot->update(['is_active' => false]);
        $this->outgoing($bot, 'wamid.OUT1', Message::UNCONFIRMED);

        [, $output] = $this->diagnose();

        $this->assertStringContainsString('chatbot apagado', $output);
        $this->assertStringContainsString('Envíos sin confirmar', $output);
    }

    public function test_the_diagnostic_is_ready_when_everything_is_configured(): void
    {
        $this->seedRoleCatalog(); // a deployed environment has its permissions: the diagnosis now checks them
        config(['n8n.execution_url' => 'https://n8n.platform.example.com/webhook', 'n8n.workflows' => ['support' => ['name' => 'S', 'n8n_workflow_id' => 'wf', 'webhook_path' => 'support-v1', 'version' => '1']]]);

        [$code, $output] = $this->diagnose();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('el webhook existe', $output);
        $this->assertStringContainsString('executions:recover está programado', $output);
    }

    public function test_in_production_a_missing_scheduler_or_horizon_blocks_and_a_running_one_passes(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['queue.default' => 'database', 'app.url' => 'https://ava.example.com', 'app.debug' => false]);
        Cache::forget('ava:scheduler:heartbeat');

        [$code, $output] = $this->diagnose();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Sin latido del scheduler', $output);

        Cache::put('ava:scheduler:heartbeat', now()->timestamp, 60);
        [, $output] = $this->diagnose();
        $this->assertStringContainsString('Scheduler corriendo', $output);

        Cache::put('ava:scheduler:heartbeat', now()->subMinutes(10)->timestamp, 60);
        [, $output] = $this->diagnose();
        $this->assertStringContainsString('lleva más de 3 minutos sin latir', $output);
    }

    public function test_horizon_is_reported_running_only_when_a_master_supervisor_exists(): void
    {
        // Regression: the check used class_exists() on an INTERFACE (always false), so it said "not running" even with Horizon up and blocked every production diagnosis.
        $this->app->detectEnvironment(fn () => 'production');
        config(['queue.default' => 'redis', 'app.url' => 'https://ava.example.com', 'app.debug' => false]);
        Cache::put('ava:scheduler:heartbeat', now()->timestamp, 60);

        $this->app->instance(MasterSupervisorRepository::class, $this->masters([]));
        [$code, $output] = $this->diagnose();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Horizon no está corriendo', $output);

        $this->app->instance(MasterSupervisorRepository::class, $this->masters(['master-1']));
        [, $output] = $this->diagnose();
        $this->assertStringContainsString('Horizon corriendo.', $output);
        $this->assertStringNotContainsString('Horizon no está corriendo', $output);
    }

    private function masters(array $names): MasterSupervisorRepository
    {
        return new class($names) implements MasterSupervisorRepository
        {
            public function __construct(private readonly array $names) {}

            public function names()
            {
                return $this->names;
            }

            public function all()
            {
                return $this->names;
            }

            public function find($name)
            {
                return null;
            }

            public function get(array $names)
            {
                return [];
            }

            public function update($master) {}

            public function forget($name) {}

            public function flushExpired() {}
        };
    }

    public function test_the_scheduler_registers_its_own_heartbeat(): void
    {
        $names = collect(app(Schedule::class)->events())->map(fn ($event) => $event->description)->all();

        $this->assertContains('scheduler-heartbeat', $names);
    }

    public function test_the_production_compose_forwards_every_integration_variable_and_never_a_value(): void
    {
        $compose = file_get_contents(base_path('docker-compose.prod.yml'));
        $names = collect(['config/n8n.php', 'config/integrations.php', 'config/services.php', 'config/workspace.php', 'config/admin.php'])
            ->flatMap(fn (string $file) => preg_match_all("/env\\('([A-Z0-9_]+)'/", file_get_contents(base_path($file)), $matches) ? $matches[1] : [])
            ->filter(fn (string $name) => preg_match('/^(META_|N8N_|WHATSAPP_|INTEGRATIONS_|WORKSPACE_|ADMIN_)/', $name))->unique();

        $this->assertNotEmpty($names);
        foreach ($names as $name) {
            // The image has no .env: a variable that is not listed under `environment` does not exist in production.
            $this->assertMatchesRegularExpression('/^\\s+'.$name.':/m', $compose, "docker-compose.prod.yml does not forward [{$name}].");
        }

        // Secrets are forwarded from the environment of the host (empty = not configured), never written in the file.
        foreach (['META_APP_SECRET', 'META_WEBHOOK_VERIFY_TOKEN', 'N8N_EXECUTION_SECRET', 'ADMIN_PASSWORD'] as $secret) {
            $this->assertStringContainsString($secret.': ${'.$secret.':-}', $compose);
        }
        $this->assertStringContainsString('INTEGRATIONS_ALLOW_PRIVATE_HOSTS: "false"', $compose, 'Production never reaches the internal network.');
    }
}

<?php

namespace Tests\Feature;

use App\Booking\AppointmentService;
use App\Integrations\SafeHttpTarget;
use App\Jobs\DeliverWebhook;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Models\WorkspaceNotification;
use App\Services\AgentAccess;
use App\Services\ConversationControl;
use App\Services\ConversationService;
use App\Webhooks\Webhooks;
use App\Webhooks\WebhookSender;
use App\Webhooks\WebhookService;
use App\Webhooks\WebhookSigner;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Webhooks de salida dentro de Integraciones (RECOMENDACIONES.md §78): the permissions and the plan that govern them, Workspace isolation, the destination checks (SSRF) at save and at
 * delivery, the HMAC signature and the rotation grace, at-least-once delivery with retries, the events the code really produces (and nothing else), publishing only after commit,
 * and that no secret or payload is ever shown again. Every request to the outside world is faked: nothing here reaches a network.
 */
class WebhooksTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'integrations', 'assistants', 'conversations', 'customers', 'service_catalog', 'professionals', 'bookings', 'product_catalog', 'orders', 'locations'];

    private const URL = 'https://hooks.example.test/ava';

    private Workspace $workspace;

    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();

        Webhooks::forgetReady();
        $this->seedRoleCatalog();
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
        Role::findOrCreate('lector_hooks', 'web')->syncPermissions(['view-integrations', 'view-webhooks']);
        Role::findOrCreate('sin_hooks', 'web')->syncPermissions(['view-integrations']);

        $this->workspace = $this->bookingWorkspace('CLIENTE_A', self::MODULES);
        $this->other = $this->bookingWorkspace('CLIENTE_B', self::MODULES);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        Webhooks::forgetReady();

        parent::tearDown();
    }

    /** @param  list<string>|null  $events */
    private function endpoint(?Workspace $workspace = null, ?array $events = null, string $url = self::URL, string $name = 'Mi sistema'): array
    {
        $created = app(WebhookService::class)->create($workspace ?? $this->workspace, ['name' => $name, 'url' => $url, 'events' => $events ?? array_keys(config('webhooks.events'))], null);

        return [$created['endpoint'], $created['secret']];
    }

    private function bot(?Workspace $workspace = null): Chatbot
    {
        return ($workspace ?? $this->workspace)->chatbots()->create(['name' => 'Asistente']);
    }

    private function inbound(Chatbot $bot, string $text = 'Hola, quiero una cita', string $direction = 'in', bool $simulated = false, string $contact = '573001112233'): array
    {
        return app(ConversationService::class)->record($bot, 'whatsapp', [
            'contact_id' => $contact, 'direction' => $direction, 'type' => 'text', 'body' => $text, 'external_id' => 'wamid.'.uniqid(), 'sent_at' => now(), 'simulated' => $simulated, 'contact_name' => 'Laura Pérez',
        ]);
    }

    private function deliveries(?WebhookEndpoint $endpoint = null)
    {
        return WebhookDelivery::query()->when($endpoint, fn ($q) => $q->where('webhook_endpoint_id', $endpoint->id))->orderBy('id')->get();
    }

    private function sent(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0])->values()->all();
    }

    // --- events the code really produces ---------------------------------------------------------------------------------------------

    public function test_a_first_message_publishes_conversation_created_and_message_received_without_the_text_or_the_contact(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$endpoint] = $this->endpoint();

        $this->inbound($this->bot());

        $rows = $this->deliveries($endpoint);
        $this->assertSame(['conversation.created', 'message.received'], $rows->pluck('event')->all());
        $this->assertSame(['delivered', 'delivered'], $rows->pluck('status')->all());

        $payload = json_decode($rows[1]->payload, true);
        $this->assertSame($this->workspace->id, $payload['workspace_id']);
        $this->assertSame('message.received', $payload['type']);
        $this->assertSame(['message_id', 'conversation_id', 'channel', 'type'], array_keys($payload['data']));

        foreach ($rows as $row) {
            foreach (['Hola, quiero una cita', 'Laura', '573001112233', 'wamid'] as $private) {
                $this->assertStringNotContainsString($private, $row->payload, "the payload must not carry «{$private}»");
            }
        }
    }

    public function test_a_second_message_publishes_only_message_received_and_the_assistant_replies_and_the_simulator_publish_nothing(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$endpoint] = $this->endpoint();
        $bot = $this->bot();

        $this->inbound($bot);
        $this->inbound($bot, 'otra cosa');
        $this->assertSame(['conversation.created', 'message.received', 'message.received'], $this->deliveries($endpoint)->pluck('event')->all());

        $before = $this->deliveries()->count();
        $this->inbound($bot, 'respuesta del asistente', 'out');
        $this->inbound($bot, 'demo', 'in', true, '573009990000');
        $this->assertSame($before, $this->deliveries()->count(), 'outgoing messages and DEMO simulation are not events');
    }

    public function test_a_replayed_provider_message_is_not_published_twice(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$endpoint] = $this->endpoint();
        $bot = $this->bot();
        $data = ['contact_id' => '573001112233', 'direction' => 'in', 'type' => 'text', 'body' => 'x', 'external_id' => 'wamid.REPEATED', 'sent_at' => now()];

        app(ConversationService::class)->record($bot, 'whatsapp', $data);
        app(ConversationService::class)->record($bot, 'whatsapp', $data);

        $this->assertSame(1, $this->deliveries($endpoint)->where('event', 'message.received')->count());
    }

    public function test_every_change_of_who_handles_a_conversation_publishes_one_event_with_the_new_version(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$endpoint] = $this->endpoint(null, ['conversation.handling_changed']);
        $conversation = $this->inbound($this->bot())['conversation'];
        $agent = $this->member($this->workspace, 'agente');

        app(ConversationControl::class)->requestHuman($conversation, 'cliente molesto');
        app(ConversationControl::class)->take($conversation->fresh(), $agent);

        $rows = $this->deliveries($endpoint);
        $this->assertCount(2, $rows);
        $first = json_decode($rows[0]->payload, true)['data'];
        $second = json_decode($rows[1]->payload, true)['data'];
        $this->assertSame(['requested', 'pending'], [$first['action'], $first['handling']]);
        $this->assertSame(['taken', 'human'], [$second['action'], $second['handling']]);
        $this->assertGreaterThan($first['handling_version'], $second['handling_version']);
        $this->assertStringNotContainsString('cliente molesto', $rows[0]->payload, 'the handoff reason is free text and never leaves');
    }

    public function test_appointments_publish_created_rescheduled_cancelled_and_status_changes_without_customer_data(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$endpoint] = $this->endpoint();
        $service = $this->makeService($this->workspace);
        $professional = $this->makeProfessional($this->workspace, [$service]);
        $start = CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));

        $appointment = app(AppointmentService::class)->book($this->workspace, $service, $professional->id, $start, ['name' => 'Laura Pérez', 'phone' => '+573001112233', 'email' => 'laura@example.test'], 'admin', 'nota privada')['appointment'];
        app(AppointmentService::class)->reschedule($appointment->fresh(), $start->addHour(), $professional->id, 'admin');
        app(AppointmentService::class)->cancel($appointment->fresh(), 'cambio de planes', 'admin');

        // A second one that takes place: staff marks it done once it started.
        $second = app(AppointmentService::class)->book($this->workspace, $service, $professional->id, CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'), ['name' => 'Laura Pérez', 'phone' => '+573001112233', 'email' => 'laura@example.test'], 'admin')['appointment'];
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
        app(AppointmentService::class)->transition($second->fresh(), Appointment::COMPLETED);

        $rows = $this->deliveries($endpoint);
        $this->assertSame(['appointment.created', 'appointment.rescheduled', 'appointment.cancelled', 'appointment.created', 'appointment.status_changed'], $rows->pluck('event')->all());
        $created = json_decode($rows[0]->payload, true)['data'];
        $this->assertSame($appointment->id, $created['appointment_id']);
        $this->assertSame('2026-10-13T10:00:00Z', $created['starts_at']);
        $this->assertSame('2026-10-13T11:00:00Z', json_decode($rows[1]->payload, true)['data']['starts_at']);
        $this->assertSame('cancelled', json_decode($rows[2]->payload, true)['data']['status']);
        $this->assertSame(['completed', $second->id], [json_decode($rows[4]->payload, true)['data']['status'], json_decode($rows[4]->payload, true)['data']['appointment_id']]);

        foreach ($rows as $row) {
            foreach (['Laura', '573001112233', 'laura@example.test', 'nota privada', 'cambio de planes'] as $private) {
                $this->assertStringNotContainsString($private, $row->payload);
            }
        }
    }

    public function test_orders_publish_created_and_status_changed_without_customer_data_and_an_idempotent_replay_publishes_nothing(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$endpoint] = $this->endpoint();
        $product = $this->workspace->products()->create(['name' => 'Hamburguesa', 'price' => '18500.00', 'sku' => null])->fresh();
        $this->actAsMember($this->workspace);

        $this->post('/orders', ['customer_name' => 'Laura Pérez', 'customer_phone' => '+573001112233', 'delivery_method' => 'pickup', 'notes' => 'sin cebolla', 'lines' => [['product_id' => $product->id, 'quantity' => 2]]])->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->post("/orders/{$order->id}/status", ['status' => 'confirmed'])->assertSessionHasNoErrors();

        $rows = $this->deliveries($endpoint);
        $this->assertSame(['order.created', 'order.status_changed'], $rows->pluck('event')->all());
        $created = json_decode($rows[0]->payload, true)['data'];
        $this->assertSame([$order->id, $order->number, 'received', '37000.00'], [$created['order_id'], $created['number'], $created['status'], $created['total']]);
        $this->assertSame('confirmed', json_decode($rows[1]->payload, true)['data']['status']);

        foreach ($rows as $row) {
            foreach (['Laura', '573001112233', 'sin cebolla', 'Hamburguesa'] as $private) {
                $this->assertStringNotContainsString($private, $row->payload);
            }
        }
    }

    public function test_the_catalog_lists_only_events_with_a_real_origin_in_the_code(): void
    {
        foreach (config('webhooks.events') as $key => $event) {
            [$class, $method] = explode('::', $event['origin']);
            $file = collect(glob(app_path('{*,*/*}/'.$class.'.php'), GLOB_BRACE))->first();
            $this->assertNotNull($file, "{$key}: {$class} does not exist");
            $this->assertStringContainsString("function {$method}", file_get_contents($file), "{$key}: {$event['origin']} does not exist");
            $this->assertStringContainsString("'{$key}'", file_get_contents($file), "{$key}: not published in {$class}");
        }

        $this->assertNotContains(config('webhooks.test_event'), array_keys(config('webhooks.events')), 'the test event can not be subscribed to');
    }

    // --- subscription filtering and isolation -------------------------------------------------------------------------------------

    public function test_only_active_endpoints_of_the_same_workspace_that_subscribed_receive_an_event(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$wanted] = $this->endpoint(null, ['message.received'], self::URL, 'Quiere');
        [$other] = $this->endpoint(null, ['order.created'], self::URL, 'Otro evento');
        [$off] = $this->endpoint(null, ['message.received'], self::URL, 'Apagado');
        app(WebhookService::class)->setActive($off, false);
        [$foreign] = $this->endpoint($this->other, ['message.received'], self::URL, 'Ajeno');

        $this->inbound($this->bot());

        $this->assertSame(1, $this->deliveries($wanted)->count());
        $this->assertSame(0, $this->deliveries($other)->count());
        $this->assertSame(0, $this->deliveries($off)->count());
        $this->assertSame(0, $this->deliveries($foreign)->count(), 'an event never reaches a destination of another Workspace');
    }

    public function test_one_event_has_the_same_event_id_on_every_endpoint_and_one_delivery_per_endpoint(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$a] = $this->endpoint(null, ['message.received'], self::URL, 'A');
        [$b] = $this->endpoint(null, ['message.received'], 'https://otro.example.test/in', 'B');

        $this->inbound($this->bot());

        $rowA = $this->deliveries($a)->first();
        $rowB = $this->deliveries($b)->first();
        $this->assertSame($rowA->event_id, $rowB->event_id);
        $this->assertNotSame($rowA->public_id, $rowB->public_id);

        $this->expectException(QueryException::class);
        WebhookDelivery::create(['public_id' => (string) Str::uuid(), 'webhook_endpoint_id' => $a->id, 'workspace_id' => $a->workspace_id, 'event' => 'message.received', 'event_id' => $rowA->event_id, 'payload' => '{}']);
    }

    public function test_the_database_refuses_a_delivery_that_pairs_an_endpoint_with_another_workspace(): void
    {
        [$endpoint] = $this->endpoint();

        $this->expectException(QueryException::class);
        DB::table('webhook_deliveries')->insert(['public_id' => (string) Str::uuid(), 'webhook_endpoint_id' => $endpoint->id, 'workspace_id' => $this->other->id, 'event' => 'x', 'event_id' => (string) Str::uuid(), 'status' => 'pending', 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }

    // --- after commit ---------------------------------------------------------------------------------------------------------

    public function test_an_event_is_published_only_after_the_transaction_commits(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$endpoint] = $this->endpoint();

        try {
            DB::transaction(function () {
                Webhooks::publish($this->workspace, 'message.received', ['message_id' => 1]);
                $this->assertSame(0, WebhookDelivery::count(), 'nothing is queued while the transaction is still open');
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, $this->deliveries($endpoint)->count(), 'a rolled-back operation never reaches a consumer');
        Http::assertNothingSent();

        DB::transaction(fn () => Webhooks::publish($this->workspace, 'message.received', ['message_id' => 2]));
        $this->assertSame(1, $this->deliveries($endpoint)->count());
    }

    public function test_a_failure_while_publishing_never_breaks_the_business_operation(): void
    {
        [$endpoint] = $this->endpoint();
        $endpoint->forceFill(['events' => 'not-a-list'])->save();
        DB::table('webhook_endpoints')->where('id', $endpoint->id)->update(['events' => '{broken']);

        $result = $this->inbound($this->bot());

        $this->assertTrue($result['created'], 'the message is stored even if the webhook code can not read the endpoint');
    }

    public function test_without_the_webhook_tables_everything_keeps_working_and_nothing_is_published(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Webhooks::forgetReady();

        $this->assertFalse(Webhooks::ready());
        $this->assertTrue($this->inbound($this->bot())['created']);

        $this->actAsMember($this->workspace);
        $this->get('/integrations')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('webhooks.enabled', false)->where('webhooks.endpoints', []));
        $this->post('/integrations/webhooks', ['name' => 'X', 'url' => self::URL, 'events' => ['message.received']])->assertSessionHasErrors('name');
    }

    // --- signature ----------------------------------------------------------------------------------------------------------

    public function test_a_delivery_is_signed_with_hmac_over_timestamp_and_body_and_carries_the_ids(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$endpoint, $secret] = $this->endpoint(null, ['message.received']);

        $this->inbound($this->bot());

        $request = $this->sent()[0];
        $delivery = $this->deliveries($endpoint)->first();
        $timestamp = $request->header('X-Ava-Timestamp')[0];

        $this->assertSame(self::URL, $request->url());
        $this->assertSame('POST', $request->method());
        $this->assertSame($delivery->payload, $request->body(), 'the signed bytes are the stored bytes');
        $this->assertSame('sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), $secret), $request->header('X-Ava-Signature')[0]);
        $this->assertTrue(app(WebhookSigner::class)->verify($secret, $timestamp, $request->header('X-Ava-Signature')[0], $request->body()));
        $this->assertSame([$delivery->event_id, $delivery->public_id, 'message.received', '1'], [$request->header('X-Ava-Event-Id')[0], $request->header('X-Ava-Delivery')[0], $request->header('X-Ava-Event')[0], $request->header('X-Ava-Delivery-Attempt')[0]]);
        $this->assertSame('application/json', $request->header('Content-Type')[0]);
        $this->assertStringStartsWith('Ava-Webhooks/', $request->header('User-Agent')[0]);
        $this->assertEmpty($request->header('Authorization'), 'no credential of Ava or of the Workspace goes to a destination');
        $this->assertEmpty($request->header('Cookie'));
    }

    public function test_the_verifier_rejects_a_wrong_secret_a_changed_body_a_stale_timestamp_and_a_malformed_header(): void
    {
        $signer = app(WebhookSigner::class);
        $now = time();
        $good = $signer->sign('whsec_abc', $now, '{"a":1}');

        $this->assertTrue($signer->verify('whsec_abc', (string) $now, $good, '{"a":1}'));
        $this->assertFalse($signer->verify('whsec_other', (string) $now, $good, '{"a":1}'));
        $this->assertFalse($signer->verify('whsec_abc', (string) $now, $good, '{"a":2}'));
        $this->assertFalse($signer->verify('whsec_abc', (string) ($now - 600), $signer->sign('whsec_abc', $now - 600, '{"a":1}'), '{"a":1}'), 'a capture older than the tolerance is a replay');
        $this->assertFalse($signer->verify('whsec_abc', 'abc', $good, '{"a":1}'));
        $this->assertFalse($signer->verify('whsec_abc', (string) $now, substr($good, 7), '{"a":1}'), 'the sha256= prefix is part of the scheme');
    }

    public function test_the_signature_changes_on_every_attempt_but_the_body_and_the_event_id_do_not(): void
    {
        Http::fake(['*' => Http::response('no', 500)]);
        [$endpoint] = $this->endpoint(null, ['message.received']);
        $this->inbound($this->bot());

        $this->travel(2)->minutes();
        Artisan::call('webhooks:sweep');

        [$first, $second] = $this->sent();
        $this->assertSame($first->body(), $second->body());
        $this->assertSame($first->header('X-Ava-Event-Id'), $second->header('X-Ava-Event-Id'));
        $this->assertSame(['1'], $first->header('X-Ava-Delivery-Attempt'));
        $this->assertSame(['2'], $second->header('X-Ava-Delivery-Attempt'));
        $this->assertNotSame($first->header('X-Ava-Timestamp'), $second->header('X-Ava-Timestamp'));
    }

    public function test_rotating_the_secret_signs_with_the_new_one_and_keeps_the_old_one_in_a_second_header_only_during_the_grace_period(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$endpoint, $old] = $this->endpoint(null, ['message.received']);
        $new = app(WebhookService::class)->rotate($endpoint);
        $this->assertNotSame($old, $new);

        $bot = $this->bot();
        $this->inbound($bot);
        $request = $this->sent()[0];
        $timestamp = (int) $request->header('X-Ava-Timestamp')[0];
        $signer = app(WebhookSigner::class);
        $this->assertSame($signer->sign($new, $timestamp, $request->body()), $request->header('X-Ava-Signature')[0]);
        $this->assertSame($signer->sign($old, $timestamp, $request->body()), $request->header('X-Ava-Signature-Previous')[0], 'the receiver can still verify with its old secret');

        $this->travel(config('webhooks.limits.secret_grace_hours') + 1)->hours();
        $this->inbound($bot, 'otra', 'in', false, '573007770000');
        $latest = $this->sent()[count($this->sent()) - 1];
        $this->assertEmpty($latest->header('X-Ava-Signature-Previous'), 'after the grace period only the current secret signs');
    }

    // --- destination safety (SSRF) ---------------------------------------------------------------------------------------------------

    public function test_unsafe_destinations_are_refused_when_saving(): void
    {
        $this->actAsMember($this->workspace);
        $bad = [
            'http://hooks.example.test/in' => 'https',
            'https://localhost/in' => 'internas',
            'https://127.0.0.1/in' => 'internas',
            'https://10.0.0.5/in' => 'internas',
            'https://192.168.1.10/in' => 'internas',
            'https://172.16.0.1/in' => 'internas',
            'https://169.254.169.254/latest/meta-data' => 'internas',
            'https://[::1]/in' => 'internas',
            'https://[fe80::1]/in' => 'internas',
            'https://[::ffff:127.0.0.1]/in' => 'internas',
            'https://100.64.0.1/in' => 'internas',
            'https://user:pass@hooks.example.test/in' => 'usuario',
            'https://hooks.example.test/in#frag' => 'fragmento',
            'ftp://hooks.example.test/in' => 'url',
            'javascript:alert(1)' => 'url',
        ];

        $accepted = [];

        foreach ($bad as $url => $why) {
            $this->post('/integrations/webhooks', ['name' => 'Malo '.crc32($url), 'url' => $url, 'events' => ['message.received']]);

            if (WebhookEndpoint::count() > 0) {
                $accepted[] = $url;
                WebhookEndpoint::query()->delete();
            }
        }

        $this->assertSame([], $accepted, 'these destinations must be refused');

        $this->post('/integrations/webhooks', ['name' => 'Bueno', 'url' => 'https://hooks.example.test/in', 'events' => ['message.received']])->assertSessionHasNoErrors();
        $this->assertSame(1, WebhookEndpoint::count());
    }

    public function test_a_hostname_that_resolves_to_an_internal_address_is_refused(): void
    {
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['10.1.2.3']));
        $this->actAsMember($this->workspace);

        $this->post('/integrations/webhooks', ['name' => 'Rebind', 'url' => 'https://rebind.example.test/in', 'events' => ['message.received']])->assertSessionHasErrors('url');
        $this->assertSame(0, WebhookEndpoint::count());
    }

    public function test_a_destination_that_turns_internal_after_saving_is_refused_at_delivery_without_any_request(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$endpoint] = $this->endpoint(null, ['message.received']);

        // DNS rebinding: it was public when saved, it resolves to the metadata address now.
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['169.254.169.254']));
        $this->app->forgetInstance(WebhookSender::class);

        $this->inbound($this->bot());

        Http::assertNothingSent();
        $delivery = $this->deliveries($endpoint)->first();
        $this->assertSame(['abandoned', 'unsafe_target', 1], [$delivery->status, $delivery->error, $delivery->attempts]);
    }

    public function test_a_redirect_is_never_followed(): void
    {
        Http::fake([self::URL => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest'])]);
        [$endpoint] = $this->endpoint(null, ['message.received']);

        $this->inbound($this->bot());

        $this->assertCount(1, $this->sent(), 'the Location is not requested');
        $delivery = $this->deliveries($endpoint)->first();
        $this->assertSame(['failed', 'redirect_not_followed', 302], [$delivery->status, $delivery->error, $delivery->response_status]);
    }

    public function test_the_request_is_pinned_to_the_validated_address_and_does_not_follow_redirects(): void
    {
        $client = app(SafeHttpTarget::class)->client(['scheme' => 'https', 'host' => 'hooks.example.test', 'port' => 443, 'ip' => '93.184.216.34'], 10);
        $options = $client->getOptions();

        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(10, $options['timeout']);

        if (defined('CURLOPT_RESOLVE')) {
            $this->assertSame(['hooks.example.test:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
        }
    }

    // --- retries, abandonment, switching off ----------------------------------------------------------------------------------------

    public function test_a_failing_destination_is_retried_with_backoff_and_then_abandoned(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        [$endpoint] = $this->endpoint(null, ['message.received']);
        $this->inbound($this->bot());

        $delivery = $this->deliveries($endpoint)->first();
        $this->assertSame(['failed', 1, 'http_500', 500], [$delivery->status, $delivery->attempts, $delivery->error, $delivery->response_status]);
        $this->assertEqualsWithDelta(now()->addSeconds(60)->timestamp, $delivery->next_attempt_at->timestamp, 5);

        Artisan::call('webhooks:sweep');
        $this->assertSame(1, $delivery->fresh()->attempts, 'not due yet: the sweeper does not hurry a retry');

        $backoff = config('webhooks.limits.backoff_seconds');

        foreach ($backoff as $index => $seconds) {
            $this->travel($seconds + 40)->seconds();
            Artisan::call('webhooks:sweep');
            $this->assertSame($index + 2, $delivery->fresh()->attempts);
        }

        $delivery->refresh();
        $this->assertSame('abandoned', $delivery->status);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertSame(count($backoff) + 1, $delivery->attempts);
        $this->assertSame(1, $endpoint->fresh()->consecutive_failures);

        Artisan::call('webhooks:sweep');
        $this->assertSame(count($backoff) + 1, $delivery->fresh()->attempts, 'an abandoned delivery is never sent again by itself');
    }

    public function test_a_timeout_and_a_connection_error_are_failures_with_a_normalized_code_and_no_provider_text(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds for https://secret-token@hooks')]);
        [$endpoint] = $this->endpoint(null, ['message.received']);

        $this->inbound($this->bot());

        $delivery = $this->deliveries($endpoint)->first();
        $this->assertSame(['failed', 'timeout', null], [$delivery->status, $delivery->error, $delivery->response_status]);
        $this->assertStringNotContainsString('secret-token', json_encode($delivery->toArray()));
    }

    public function test_a_success_resets_the_failure_count_and_many_abandoned_deliveries_switch_the_endpoint_off_and_tell_the_team(): void
    {
        config(['webhooks.limits.backoff_seconds' => [], 'webhooks.limits.disable_after_failures' => 3]);
        [$endpoint] = $this->endpoint(null, ['message.received']);
        $bot = $this->bot();
        $answer = 500;
        Http::fake(['*' => function () use (&$answer) {
            return Http::response('x', $answer);
        }]);
        $this->inbound($bot, 'a', 'in', false, '5731');
        $this->inbound($bot, 'b', 'in', false, '5732');
        $this->assertSame(2, $endpoint->fresh()->consecutive_failures);
        $this->assertTrue($endpoint->fresh()->is_active);

        $answer = 200;
        $this->inbound($bot, 'c', 'in', false, '5733');
        $this->assertSame(0, $endpoint->fresh()->consecutive_failures, 'any success resets the count');

        $answer = 500;

        foreach (['5734', '5735', '5736'] as $contact) {
            $this->inbound($bot, 'x', 'in', false, $contact);
        }

        $endpoint->refresh();
        $this->assertFalse($endpoint->is_active);
        $this->assertSame('failures', $endpoint->disabled_reason);
        $notice = WorkspaceNotification::where('workspace_id', $this->workspace->id)->where('type', 'webhook_disabled')->firstOrFail();
        $this->assertSame('manage-webhooks', $notice->permission);
        $this->assertStringNotContainsString('whsec_', $notice->title.$notice->body);

        $count = $this->deliveries($endpoint)->count();
        $this->inbound($bot, 'y', 'in', false, '5737');
        $this->assertSame($count, $this->deliveries($endpoint)->count(), 'a switched-off endpoint receives no more events');
    }

    public function test_the_same_attempt_can_not_be_sent_twice_even_if_two_jobs_run(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$endpoint] = $this->endpoint(null, ['message.received']);
        $this->inbound($this->bot());
        $delivery = $this->deliveries($endpoint)->first();

        (new DeliverWebhook($delivery->id))->handle(app(WebhookSender::class));
        (new DeliverWebhook($delivery->id))->handle(app(WebhookSender::class));

        $this->assertCount(1, $this->sent());
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_the_sweeper_recovers_a_lost_queue_job_and_an_attempt_that_never_reported_back(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$endpoint] = $this->endpoint(null, ['message.received']);
        $pending = $this->delivery($endpoint, ['status' => 'pending', 'next_attempt_at' => now()->subMinutes(5)]);
        $stuck = $this->delivery($endpoint, ['status' => 'sending', 'attempts' => 1, 'last_attempt_at' => now()->subMinutes(30)]);
        $future = $this->delivery($endpoint, ['status' => 'failed', 'attempts' => 1, 'next_attempt_at' => now()->addHour()]);

        Artisan::call('webhooks:sweep');

        $this->assertSame('delivered', $pending->fresh()->status);
        $this->assertSame('delivered', $stuck->fresh()->status);
        $this->assertSame(2, $stuck->fresh()->attempts);
        $this->assertSame('failed', $future->fresh()->status, 'a retry that is not due stays untouched');
    }

    public function test_a_failure_to_queue_never_loses_the_event(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$endpoint] = $this->endpoint(null, ['message.received']);
        config(['queue.default' => 'not-a-connection']);

        $this->inbound($this->bot());

        $delivery = $this->deliveries($endpoint)->first();
        $this->assertSame('pending', $delivery->status, 'the row exists even though the queue was down');

        config(['queue.default' => 'sync']);
        $this->travel(1)->minutes();
        Artisan::call('webhooks:sweep');
        $this->assertSame('delivered', $delivery->fresh()->status);
    }

    public function test_prune_removes_old_finished_deliveries_and_keeps_the_rest(): void
    {
        [$endpoint] = $this->endpoint();
        $days = config('webhooks.limits.retention_days');
        $oldDone = $this->delivery($endpoint, ['status' => 'delivered', 'created_at' => now()->subDays($days + 1)]);
        $oldFailed = $this->delivery($endpoint, ['status' => 'failed', 'created_at' => now()->subDays($days + 1)]);
        $ancientActive = $this->delivery($endpoint, ['status' => 'pending', 'created_at' => now()->subDays($days * 2 + 1)]);
        $ancientDone = $this->delivery($endpoint, ['status' => 'abandoned', 'created_at' => now()->subDays($days * 2 + 1)]);
        $recent = $this->delivery($endpoint, ['status' => 'abandoned', 'created_at' => now()->subDay()]);

        Artisan::call('webhooks:prune');

        $this->assertNull(WebhookDelivery::find($oldDone->id));
        $this->assertNotNull(WebhookDelivery::find($oldFailed->id), 'a delivery still being retried is not pruned early');
        $this->assertNotNull(WebhookDelivery::find($ancientActive->id), 'an unfinished delivery is never pruned, however old');
        $this->assertNull(WebhookDelivery::find($ancientDone->id));
        $this->assertNotNull(WebhookDelivery::find($recent->id));
        $this->assertNotNull(WebhookEndpoint::find($endpoint->id), 'the endpoint is never touched');
    }

    private function delivery(WebhookEndpoint $endpoint, array $attributes): WebhookDelivery
    {
        $delivery = WebhookDelivery::create($attributes + [
            'public_id' => (string) Str::uuid(), 'webhook_endpoint_id' => $endpoint->id, 'workspace_id' => $endpoint->workspace_id, 'event' => 'message.received',
            'event_id' => (string) Str::uuid(), 'status' => 'pending', 'payload' => Webhooks::body($endpoint->workspace_id, 'message.received', (string) Str::uuid(), ['message_id' => 1]),
        ]);

        if (isset($attributes['created_at'])) {
            DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['created_at' => $attributes['created_at']]);
        }

        return $delivery;
    }

    // --- management: permissions, plan, isolation, secrets --------------------------------------------------------------------------

    private function payload(array $overrides = []): array
    {
        return $overrides + ['name' => 'Mi sistema', 'url' => self::URL, 'events' => ['message.received', 'order.created']];
    }

    public function test_creating_shows_the_secret_once_stores_it_encrypted_and_never_returns_it_again(): void
    {
        $this->actAsMember($this->workspace);

        $this->from('/integrations')->post('/integrations/webhooks', $this->payload())->assertSessionHasNoErrors()->assertRedirect('/integrations');
        $endpoint = WebhookEndpoint::firstOrFail();

        $flashed = session('webhook_secret');
        $this->assertStringStartsWith('whsec_', $flashed['secret']);
        $this->assertSame($endpoint->id, $flashed['id']);
        $this->assertSame(substr($flashed['secret'], -4), $endpoint->secret_hint);
        $raw = DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret');
        $this->assertStringNotContainsString($flashed['secret'], $raw, 'encrypted at rest');
        $this->assertSame($flashed['secret'], $endpoint->secret);

        // The page that follows shows it once...
        $this->get('/integrations')->assertInertia(fn (AssertableInertia $page) => $page->where('newWebhookSecret.secret', $flashed['secret']));
        // ...and never again, nor in the endpoint list, the history, the audit log or a serialization.
        $second = $this->get('/integrations');
        $second->assertInertia(fn (AssertableInertia $page) => $page->where('newWebhookSecret', null)->where('webhooks.endpoints.0.secretHint', $endpoint->secret_hint));
        $this->assertStringNotContainsString($flashed['secret'], $second->getContent());
        $this->assertStringNotContainsString($flashed['secret'], $this->getJson("/integrations/webhooks/{$endpoint->id}/deliveries")->getContent());
        $this->assertStringNotContainsString($flashed['secret'], json_encode(AuditLog::all()->toArray()));
        $this->assertStringNotContainsString($flashed['secret'], json_encode($endpoint->fresh()->toArray()));
        $this->assertSame(['created'], AuditLog::where('resource_type', 'webhook_endpoint')->pluck('action')->all());
    }

    public function test_a_rotation_returns_the_new_secret_once_and_is_audited_without_it(): void
    {
        [$endpoint, $old] = $this->endpoint();
        $this->actAsMember($this->workspace);

        $this->from('/integrations')->post("/integrations/webhooks/{$endpoint->id}/rotate")->assertSessionHas('success');
        $new = session('webhook_secret')['secret'];

        $this->assertNotSame($old, $new);
        $endpoint->refresh();
        $this->assertSame($new, $endpoint->secret);
        $this->assertSame($old, $endpoint->previous_secret);
        $this->assertTrue($endpoint->previousSecretIsValid());
        $this->assertStringNotContainsString($old.$new, json_encode(AuditLog::all()->toArray()));
        $this->assertStringNotContainsString($old, json_encode(AuditLog::all()->toArray()));
        $this->assertStringNotContainsString($new, json_encode(AuditLog::all()->toArray()));
        $this->assertTrue(AuditLog::where('resource_type', 'webhook_endpoint')->where('action', 'rotated')->exists());
    }

    public function test_the_page_never_sends_a_secret_a_payload_or_a_previous_secret_to_the_browser(): void
    {
        [$endpoint, $secret] = $this->endpoint();
        $rotated = app(WebhookService::class)->rotate($endpoint);
        Http::fake(['*' => Http::response('ok')]);
        $this->inbound($this->bot());
        $this->actAsMember($this->workspace);

        $page = $this->get('/integrations')->getContent();
        $history = $this->getJson("/integrations/webhooks/{$endpoint->id}/deliveries")->getContent();

        foreach ([$secret, $rotated] as $hidden) {
            $this->assertStringNotContainsString($hidden, $page);
            $this->assertStringNotContainsString($hidden, $history);
        }

        $this->assertStringNotContainsString('payload', $history);
        $this->assertStringNotContainsString('message_id', $history);
        $this->get('/integrations')->assertInertia(fn (AssertableInertia $p) => $p->missing('webhooks.endpoints.0.secret')->missing('webhooks.endpoints.0.previous_secret')->missing('webhooks.endpoints.0.payload'));
        $this->assertStringNotContainsString('previous_secret', $page.$history);
    }

    public function test_permissions_are_enforced_by_the_server(): void
    {
        [$endpoint] = $this->endpoint();
        $reader = $this->member($this->workspace, 'lector_hooks');
        $blind = $this->member($this->workspace, 'sin_hooks');

        // A role with `view-integrations` only does not even receive the section.
        $this->actingAs($blind)->withSession(['workspace_id' => $this->workspace->id]);
        $this->get('/integrations')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('webhooks', null)->where('newWebhookSecret', null));
        $this->getJson("/integrations/webhooks/{$endpoint->id}/deliveries")->assertForbidden();
        $this->post('/integrations/webhooks', $this->payload())->assertForbidden();

        // A reader sees the list and the history, and changes nothing.
        $this->app['auth']->forgetGuards();
        $this->actingAs($reader)->withSession(['workspace_id' => $this->workspace->id]);
        $this->get('/integrations')->assertInertia(fn (AssertableInertia $page) => $page->where('webhooks.endpoints.0.id', $endpoint->id));
        $this->getJson("/integrations/webhooks/{$endpoint->id}/deliveries")->assertOk();
        $this->post('/integrations/webhooks', $this->payload(['name' => 'Otro']))->assertForbidden();
        $this->put("/integrations/webhooks/{$endpoint->id}", $this->payload(['name' => 'Cambio']))->assertForbidden();
        $this->post("/integrations/webhooks/{$endpoint->id}/rotate")->assertForbidden();
        $this->post("/integrations/webhooks/{$endpoint->id}/test")->assertForbidden();
        $this->post("/integrations/webhooks/{$endpoint->id}/deactivate")->assertForbidden();
        $this->delete("/integrations/webhooks/{$endpoint->id}")->assertForbidden();
        $this->assertSame('Mi sistema', $endpoint->fresh()->name);
        $this->assertTrue($endpoint->fresh()->is_active);

        // Anonymous visitors are sent to log in.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        auth()->logout();
        $this->post('/integrations/webhooks', $this->payload())->assertRedirect();
    }

    public function test_a_workspace_without_the_integrations_module_cannot_use_webhooks(): void
    {
        $this->contractModules($this->workspace, ['dashboard']);
        [$endpoint] = $this->endpoint();
        $this->actAsMember($this->workspace);

        $this->get('/integrations')->assertForbidden();
        $this->post('/integrations/webhooks', $this->payload(['name' => 'Otro']))->assertForbidden();
        $this->post("/integrations/webhooks/{$endpoint->id}/rotate")->assertForbidden();
        $this->assertSame(1, WebhookEndpoint::count());
    }

    public function test_an_endpoint_or_delivery_of_another_workspace_does_not_exist_from_here(): void
    {
        [$foreign] = $this->endpoint($this->other, null, self::URL, 'Ajeno');
        $theirs = $this->delivery($foreign, ['status' => 'failed']);
        [$mine] = $this->endpoint();
        $this->actAsMember($this->workspace);

        $this->put("/integrations/webhooks/{$foreign->id}", $this->payload())->assertNotFound();
        $this->post("/integrations/webhooks/{$foreign->id}/activate")->assertNotFound();
        $this->post("/integrations/webhooks/{$foreign->id}/deactivate")->assertNotFound();
        $this->post("/integrations/webhooks/{$foreign->id}/rotate")->assertNotFound();
        $this->post("/integrations/webhooks/{$foreign->id}/test")->assertNotFound();
        $this->getJson("/integrations/webhooks/{$foreign->id}/deliveries")->assertNotFound();
        $this->post("/integrations/webhooks/{$foreign->id}/deliveries/{$theirs->id}/retry")->assertNotFound();
        $this->post("/integrations/webhooks/{$mine->id}/deliveries/{$theirs->id}/retry")->assertNotFound();
        $this->delete("/integrations/webhooks/{$foreign->id}")->assertNotFound();

        $this->assertSame('Ajeno', $foreign->fresh()->name);
        $this->assertSame('failed', $theirs->fresh()->status);

        $this->get('/integrations')->assertInertia(fn (AssertableInertia $page) => $page->has('webhooks.endpoints', 1)->where('webhooks.endpoints.0.id', $mine->id));
    }

    public function test_a_workspace_id_sent_by_the_browser_is_ignored(): void
    {
        $this->actAsMember($this->workspace);

        $this->post('/integrations/webhooks', $this->payload(['workspace_id' => $this->other->id, 'secret' => 'whsec_chosen_by_browser', 'consecutive_failures' => 9, 'is_active' => false]))->assertSessionHasNoErrors();

        $endpoint = WebhookEndpoint::firstOrFail();
        $this->assertSame($this->workspace->id, $endpoint->workspace_id);
        $this->assertNotSame('whsec_chosen_by_browser', $endpoint->secret);
        $this->assertSame([0, true], [$endpoint->consecutive_failures, $endpoint->is_active]);
    }

    public function test_a_platform_administrator_looking_at_another_workspace_gets_no_webhooks(): void
    {
        [$endpoint] = $this->endpoint($this->other);
        config(['workspace.admin_code' => $this->workspace->code]);
        $user = $this->member($this->workspace);
        $user->forceFill(['is_superuser' => true])->save();
        $this->actingAs($user)->withSession(['workspace_id' => $this->workspace->id]);

        $this->get('/integrations?workspace='.$this->other->id)->assertInertia(fn (AssertableInertia $page) => $page->where('scope.readOnly', true)->where('webhooks', null)->where('newWebhookSecret', null));
        $this->get('/integrations')->assertInertia(fn (AssertableInertia $page) => $page->where('scope.readOnly', false)->has('webhooks.endpoints', 0));
    }

    public function test_validation_limits_the_name_the_events_and_the_number_of_endpoints(): void
    {
        $this->actAsMember($this->workspace);

        $this->post('/integrations/webhooks', $this->payload(['name' => '']))->assertSessionHasErrors('name');
        $this->post('/integrations/webhooks', $this->payload(['name' => str_repeat('x', 81)]))->assertSessionHasErrors('name');
        $this->post('/integrations/webhooks', $this->payload(['events' => []]))->assertSessionHasErrors('events');
        $this->post('/integrations/webhooks', $this->payload(['events' => ['order.deleted']]))->assertSessionHasErrors('events.0');
        $this->post('/integrations/webhooks', $this->payload(['events' => [config('webhooks.test_event')]]))->assertSessionHasErrors('events.0');
        $this->post('/integrations/webhooks', $this->payload(['url' => str_repeat('a', 2100)]))->assertSessionHasErrors('url');

        $this->post('/integrations/webhooks', $this->payload())->assertSessionHasNoErrors();
        $this->post('/integrations/webhooks', $this->payload())->assertSessionHasErrors('name');
        $this->post('/integrations/webhooks', $this->payload(['name' => 'Mi sistema']), [])->assertSessionHasErrors('name');

        config(['webhooks.limits.max_endpoints' => 2]);
        $this->post('/integrations/webhooks', $this->payload(['name' => 'Segundo']))->assertSessionHasNoErrors();
        $this->post('/integrations/webhooks', $this->payload(['name' => 'Tercero']))->assertSessionHasErrors('name');
        $this->assertSame(2, WebhookEndpoint::count());

        // The same name is fine in another Workspace.
        $this->assertNotNull($this->endpoint($this->other, null, self::URL, 'Mi sistema')[0]);
    }

    public function test_editing_changes_the_destination_and_events_audits_only_the_host_and_keeps_the_secret(): void
    {
        [$endpoint, $secret] = $this->endpoint(null, ['message.received'], 'https://hooks.example.test/in/SECRET-PATH-KEY?token=abc');
        $this->actAsMember($this->workspace);

        $this->put("/integrations/webhooks/{$endpoint->id}", $this->payload(['name' => 'Renombrado', 'url' => 'https://nuevo.example.test/x', 'events' => ['order.created', 'message.received']]))->assertSessionHasNoErrors();

        $endpoint->refresh();
        $this->assertSame(['Renombrado', 'https://nuevo.example.test/x'], [$endpoint->name, $endpoint->url]);
        $this->assertSame(['message.received', 'order.created'], $endpoint->events, 'catalog order, no repeats');
        $this->assertSame($secret, $endpoint->secret, 'editing never changes the secret');

        $audit = json_encode(AuditLog::where('resource_type', 'webhook_endpoint')->get()->toArray());
        $this->assertStringContainsString('hooks.example.test', $audit);
        $this->assertStringNotContainsString('SECRET-PATH-KEY', $audit);
        $this->assertStringNotContainsString('token=abc', $audit);
    }

    public function test_activating_and_deactivating_stop_and_resume_events_and_reactivating_clears_the_failure_state(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$endpoint] = $this->endpoint(null, ['message.received']);
        $endpoint->forceFill(['is_active' => false, 'disabled_reason' => 'failures', 'consecutive_failures' => 10])->save();
        $this->actAsMember($this->workspace);
        $bot = $this->bot();

        $this->inbound($bot);
        $this->assertSame(0, $this->deliveries($endpoint)->count());

        $this->post("/integrations/webhooks/{$endpoint->id}/activate")->assertSessionHas('success');
        $endpoint->refresh();
        $this->assertSame([true, null, 0], [$endpoint->is_active, $endpoint->disabled_reason, $endpoint->consecutive_failures]);

        $this->inbound($bot, 'dos', 'in', false, '5731');
        $this->assertSame(1, $this->deliveries($endpoint)->count());

        $this->post("/integrations/webhooks/{$endpoint->id}/deactivate")->assertSessionHas('success');
        $this->assertSame('manual', $endpoint->fresh()->disabled_reason);
    }

    public function test_deleting_an_endpoint_removes_its_history_and_is_audited(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$endpoint] = $this->endpoint(null, ['message.received']);
        $this->inbound($this->bot());
        $this->actAsMember($this->workspace);

        $this->delete("/integrations/webhooks/{$endpoint->id}")->assertSessionHas('success');

        $this->assertSame([0, 0], [WebhookEndpoint::count(), WebhookDelivery::count()]);
        $this->assertTrue(AuditLog::where('resource_type', 'webhook_endpoint')->where('action', 'deleted')->exists());
    }

    public function test_the_test_button_sends_a_signed_test_event_and_is_rate_limited(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        [$endpoint, $secret] = $this->endpoint(null, ['message.received']);
        $this->actAsMember($this->workspace);

        $this->post("/integrations/webhooks/{$endpoint->id}/test")->assertSessionHas('success');

        $request = $this->sent()[0];
        $this->assertSame('webhook.test', $request->header('X-Ava-Event')[0]);
        $this->assertTrue(app(WebhookSigner::class)->verify($secret, $request->header('X-Ava-Timestamp')[0], $request->header('X-Ava-Signature')[0], $request->body()));
        $this->assertSame('webhook.test', json_decode($request->body(), true)['type']);

        for ($i = 1; $i < config('webhooks.limits.tests_per_minute'); $i++) {
            $this->post("/integrations/webhooks/{$endpoint->id}/test")->assertSessionHas('success');
        }

        $this->post("/integrations/webhooks/{$endpoint->id}/test")->assertStatus(429);
    }

    public function test_the_history_lists_deliveries_newest_first_and_a_failed_one_can_be_retried_with_the_same_event_id(): void
    {
        $answer = 500;
        Http::fake(['*' => function () use (&$answer) {
            return Http::response('x', $answer);
        }]);
        [$endpoint] = $this->endpoint(null, ['message.received']);
        $this->inbound($this->bot());
        $delivery = $this->deliveries($endpoint)->first();
        $this->actAsMember($this->workspace);

        $row = $this->getJson("/integrations/webhooks/{$endpoint->id}/deliveries")->assertOk()->json('deliveries.0');
        $this->assertSame(['message.received', 'failed', 1, 500, 'http_500', true], [$row['event'], $row['status'], $row['attempts'], $row['responseStatus'], $row['error'], $row['canRetry']]);
        $this->assertSame($delivery->event_id, $row['eventId']);
        $this->assertArrayNotHasKey('payload', $row);

        $answer = 200;
        $this->post("/integrations/webhooks/{$endpoint->id}/deliveries/{$delivery->id}/retry")->assertSessionHas('success');

        $delivery->refresh();
        $this->assertSame(['delivered', 1], [$delivery->status, $delivery->attempts]);
        $this->assertSame($delivery->event_id, $this->sent()[count($this->sent()) - 1]->header('X-Ava-Event-Id')[0]);
        $this->assertTrue(AuditLog::where('resource_type', 'webhook_endpoint')->where('action', 'retried')->exists());

        $this->post("/integrations/webhooks/{$endpoint->id}/deliveries/{$delivery->id}/retry")->assertSessionHasErrors('delivery');
    }

    public function test_a_delivered_payload_stays_under_the_size_limit(): void
    {
        config(['webhooks.limits.max_payload_bytes' => 200]);

        $this->expectException(\LengthException::class);
        Webhooks::body($this->workspace->id, 'message.received', 'id', ['message_id' => str_repeat('x', 500)]);
    }

    // --- compatibility with what already exists ---------------------------------------------------------------------------------------

    public function test_the_existing_provider_and_agent_endpoints_are_untouched(): void
    {
        // Wompi still judges its own signature; Meta/WhatsApp still answers as before; neither is a Workspace webhook.
        $this->postJson('/api/webhooks/wompi', [])->assertStatus(400);
        $this->postJson('/api/webhooks/whatsapp', [])->assertNotFound();
        $this->assertTrue(Route::has('webhooks.wompi.receive'));
        $this->assertTrue(Route::has('webhooks.whatsapp.integration.receive'));

        // The agent (n8n) token keeps reading its configuration and creates no webhook state.
        $bot = $this->bot();
        $token = app(AgentAccess::class)->generate($bot);
        $this->withToken($token)->getJson('/api/agent/config')->assertOk();
        $this->assertSame(0, WebhookEndpoint::count());
    }

    public function test_inbound_provider_routes_are_not_managed_by_outbound_webhooks(): void
    {
        $inbound = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/webhooks/'));
        $this->assertGreaterThanOrEqual(3, $inbound->count());

        foreach ($inbound as $route) {
            $this->assertNotContains('workspace', $route->gatherMiddleware(), 'a provider callback has no Workspace session');
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'integrations/webhooks')) {
                $this->assertContains('workspace', $route->gatherMiddleware(), $route->uri().' is behind the Workspace context');
                $this->assertNotEmpty(array_filter($route->gatherMiddleware(), fn ($m) => str_starts_with($m, 'workspace.permission:')), $route->uri().' needs a permission');
            }
        }
    }

    public function test_every_webhook_management_route_carries_the_general_rate_limit(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'integrations/webhooks')) {
                $this->assertContains('throttle:authenticated', $route->gatherMiddleware(), $route->uri());
            }
        }
    }

    public function test_the_user_model_is_not_needed_to_publish(): void
    {
        $this->assertInstanceOf(User::class, $this->member($this->workspace));
        $this->assertNull(app(WebhookService::class)->create($this->workspace, ['name' => 'Sin actor', 'url' => self::URL, 'events' => ['order.created']], null)['endpoint']->created_by_user_id);
        $this->assertSame([], Conversation::all()->all());
    }
}

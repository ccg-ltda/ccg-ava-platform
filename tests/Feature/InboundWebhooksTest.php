<?php

namespace Tests\Feature;

use App\Integrations\SafeHttpTarget;
use App\Models\AuditLog;
use App\Models\InboundEndpoint;
use App\Models\InboundEvent;
use App\Models\Workspace;
use App\Webhooks\InboundWebhooks;
use App\Webhooks\InboundWebhookService;
use App\Webhooks\WebhookService;
use App\Webhooks\WebhookSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Inbound webhook endpoints (Integraciones > Webhooks > «Endpoints entrantes»): the URL Ava generates and the customer's system calls. Management is server-checked (plan, the two
 * webhook permissions, Workspace isolation, the secret shown once); the receiving side is judged by its signature (HMAC over timestamp and body), a replay window, a unique event id,
 * size and rate limits, and it never executes anything nor stores the body. It is independent of the outbound webhooks and of the provider webhooks (Meta, Wompi).
 */
class InboundWebhooksTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'integrations', 'assistants', 'conversations'];

    private Workspace $a;

    private Workspace $b;

    protected function setUp(): void
    {
        parent::setUp();

        InboundWebhooks::forgetReady();
        $this->seedRoleCatalog();
        Role::findOrCreate('lector_in', 'web')->syncPermissions(['view-integrations', 'view-webhooks']);
        $this->a = $this->bookingWorkspace('IN_A', self::MODULES);
        $this->b = $this->bookingWorkspace('IN_B', self::MODULES);
        Cache::flush();
    }

    /** @return array{0: InboundEndpoint, 1: string} */
    private function endpoint(?Workspace $workspace = null, string $name = 'Mi sistema'): array
    {
        $created = app(InboundWebhookService::class)->create($workspace ?? $this->a, $name, null);

        return [$created['endpoint'], $created['secret']];
    }

    /** @return array<string, string> */
    private function signed(string $secret, string $body, ?int $timestamp = null, string $eventId = 'evt-00000001'): array
    {
        $timestamp ??= now()->timestamp;

        return ['X-Ava-Timestamp' => (string) $timestamp, 'X-Ava-Signature' => app(WebhookSigner::class)->sign($secret, $timestamp, $body), 'X-Ava-Event-Id' => $eventId, 'Content-Type' => 'application/json'];
    }

    private function receive(InboundEndpoint $endpoint, string $body, array $headers)
    {
        return $this->call('POST', "/api/webhooks/in/{$endpoint->public_key}", [], [], [], $this->server($headers), $body);
    }

    /** @return array<string, string> */
    private function server(array $headers): array
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $server[($name === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name)))] = $value;
        }

        return $server;
    }

    // --- receiving ----------------------------------------------------------------------------------------------------------------

    public function test_a_correctly_signed_event_is_accepted_and_recorded_without_its_body(): void
    {
        [$endpoint, $secret] = $this->endpoint();
        $body = json_encode(['type' => 'customer.updated', 'secreto_del_cliente' => 'NO-GUARDAR']);

        $this->receive($endpoint, $body, $this->signed($secret, $body))->assertStatus(202)->assertJson(['status' => 'accepted', 'id' => 'evt-00000001']);

        $event = InboundEvent::first();
        $this->assertSame(['evt-00000001', 'customer.updated', strlen($body), $this->a->id], [$event->event_id, $event->event_type, $event->bytes, $event->workspace_id]);
        $this->assertStringNotContainsString('NO-GUARDAR', json_encode($event->getAttributes()));
        $this->assertSame([1, 0], [$endpoint->fresh()->received_count, $endpoint->fresh()->rejected_count]);
        $this->assertNotNull($endpoint->fresh()->last_received_at);
    }

    public function test_a_wrong_signature_a_changed_body_a_stale_timestamp_and_a_missing_header_are_refused_the_same_way(): void
    {
        [$endpoint, $secret] = $this->endpoint();
        $body = '{"type":"x"}';

        $this->receive($endpoint, $body, $this->signed('whin_otro_secreto', $body))->assertStatus(401)->assertExactJson(['error' => 'invalid_signature']);
        $this->receive($endpoint, '{"type":"changed"}', $this->signed($secret, $body))->assertStatus(401);
        $this->receive($endpoint, $body, $this->signed($secret, $body, now()->timestamp - 3600))->assertStatus(401);
        $this->receive($endpoint, $body, $this->signed($secret, $body, now()->timestamp + 3600))->assertStatus(401);
        $this->receive($endpoint, $body, ['X-Ava-Event-Id' => 'evt-00000001'])->assertStatus(401);

        $this->assertSame(0, InboundEvent::count());
        $this->assertSame(5, $endpoint->fresh()->rejected_count);
    }

    public function test_the_same_event_id_is_acknowledged_once_and_not_recorded_twice(): void
    {
        [$endpoint, $secret] = $this->endpoint();
        $body = '{"type":"x"}';

        $this->receive($endpoint, $body, $this->signed($secret, $body))->assertStatus(202);
        $this->receive($endpoint, $body, $this->signed($secret, $body))->assertStatus(200)->assertJson(['status' => 'duplicate']);
        $this->receive($endpoint, $body, $this->signed($secret, $body, null, 'evt-00000002'))->assertStatus(202);

        $this->assertSame(2, InboundEvent::count());
        $this->assertSame(2, $endpoint->fresh()->received_count);
    }

    public function test_the_event_id_and_the_body_are_validated(): void
    {
        [$endpoint, $secret] = $this->endpoint();
        $body = '{"type":"x"}';

        $this->receive($endpoint, $body, $this->signed($secret, $body, null, 'corto'))->assertStatus(422)->assertJson(['error' => 'event_id_required']);
        $headers = $this->signed($secret, $body);
        unset($headers['X-Ava-Event-Id']);
        $this->receive($endpoint, $body, $headers)->assertStatus(422);
        $this->receive($endpoint, 'no es json', $this->signed($secret, 'no es json'))->assertStatus(422)->assertJson(['error' => 'invalid_body']);
        $this->receive($endpoint, '[1,2]', $this->signed($secret, '[1,2]'))->assertStatus(422);
        $this->assertSame(0, InboundEvent::count());
    }

    public function test_an_unknown_inactive_or_plan_less_endpoint_is_the_same_404_and_the_workspace_never_comes_from_the_request(): void
    {
        [$endpoint, $secret] = $this->endpoint();
        [$other, $otherSecret] = $this->endpoint($this->b, 'Otro');
        $body = '{"type":"x"}';

        $this->call('POST', '/api/webhooks/in/'.str_repeat('a', 48), [], [], [], $this->server($this->signed($secret, $body)), $body)->assertStatus(404)->assertExactJson(['error' => 'not_found']);

        app(InboundWebhookService::class)->setActive($endpoint, false);
        $this->receive($endpoint, $body, $this->signed($secret, $body))->assertStatus(404)->assertExactJson(['error' => 'not_found']);

        app(InboundWebhookService::class)->setActive($endpoint, true);
        $this->contractModules($this->a, ['dashboard']);
        $this->receive($endpoint->fresh(), $body, $this->signed($secret, $body))->assertStatus(404);
        $this->contractModules($this->a, self::MODULES);

        // Signing for A's endpoint with B's secret, or sending another Workspace in the query / header, changes nothing.
        $this->call('POST', "/api/webhooks/in/{$endpoint->public_key}?workspace_id={$this->b->id}", [], [], [], $this->server($this->signed($otherSecret, $body) + ['X-Workspace-Id' => (string) $this->b->id]), $body)->assertStatus(401);
        $this->receive($endpoint->fresh(), $body, $this->signed($secret, $body))->assertStatus(202);
        $this->assertSame($this->a->id, InboundEvent::first()->workspace_id);
        $this->assertSame(0, $other->fresh()->received_count);
    }

    public function test_rotating_keeps_the_previous_secret_during_the_grace_period_only(): void
    {
        [$endpoint, $old] = $this->endpoint();
        $new = app(InboundWebhookService::class)->rotate($endpoint);
        $body = '{"type":"x"}';

        $this->receive($endpoint->fresh(), $body, $this->signed($new, $body, null, 'evt-new-00001'))->assertStatus(202);
        $this->receive($endpoint->fresh(), $body, $this->signed($old, $body, null, 'evt-old-00001'))->assertStatus(202);

        $this->travel(25)->hours();
        $this->receive($endpoint->fresh(), $body, $this->signed($old, $body, now()->timestamp, 'evt-old-00002'))->assertStatus(401);
        $this->receive($endpoint->fresh(), $body, $this->signed($new, $body, now()->timestamp, 'evt-new-00002'))->assertStatus(202);
    }

    public function test_size_and_rate_limits_apply_and_a_flood_of_bad_signatures_does_not_fill_the_history(): void
    {
        [$endpoint, $secret] = $this->endpoint();
        config(['api_protection.provider.max_body_kb' => 1]);
        $big = json_encode(['type' => 'x', 'pad' => str_repeat('a', 4096)]);
        $this->receive($endpoint, $big, $this->signed($secret, $big))->assertStatus(413);
        config(['api_protection.provider.max_body_kb' => 1024]);

        foreach (range(1, 60) as $i) {
            $this->receive($endpoint, '{}', $this->signed('mal', '{}'));
        }

        $this->receive($endpoint, '{}', $this->signed($secret, '{}'))->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(0, InboundEvent::count());
    }

    public function test_the_provider_webhooks_are_untouched(): void
    {
        $this->assertTrue(Route::has('webhooks.whatsapp.receive'));
        $this->assertTrue(Route::has('webhooks.wompi.receive'));
        $this->assertTrue(Route::has('webhooks.inbound.receive'));
    }

    // --- management ------------------------------------------------------------------------------------------------------------------

    public function test_creating_shows_the_secret_once_and_the_page_never_sends_it_again(): void
    {
        $this->actAsMember($this->a);

        $this->post('/integrations/webhooks/inbound', ['name' => 'Mi sistema'])->assertSessionHasNoErrors();
        $endpoint = InboundEndpoint::first();
        $this->assertSame($this->a->id, $endpoint->workspace_id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $endpoint->public_key);

        $page = $this->get('/integrations');
        $page->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('newInboundSecret.name', 'Mi sistema')->where('inboundWebhooks.endpoints.0.url', route('webhooks.inbound.receive', $endpoint->public_key)));
        $secret = data_get($page->viewData('page'), 'props.newInboundSecret.secret');
        $this->assertStringStartsWith('whin_', $secret);

        $again = $this->get('/integrations');
        $this->assertStringNotContainsString($secret, $again->getContent());
        $this->assertStringNotContainsString($secret, json_encode(AuditLog::where('resource_type', 'inbound_endpoint')->get()->toArray()));
        $this->assertNotSame($secret, DB::table('inbound_endpoints')->value('secret'), 'stored encrypted');
        $this->assertSame($secret, $endpoint->fresh()->secret);
    }

    public function test_it_is_never_mixed_with_the_outbound_webhooks_and_their_secrets(): void
    {
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        [$endpoint, $secret] = $this->endpoint();
        $outbound = app(WebhookService::class)->create($this->a, ['name' => 'Salida', 'url' => 'https://hooks.example.test/x', 'events' => ['message.received']], null);

        $this->assertStringStartsWith('whin_', $secret);
        $this->assertStringStartsWith('whsec_', $outbound['secret']);
        $this->assertNotSame($secret, $outbound['secret']);
        $body = '{"type":"x"}';
        $this->receive($endpoint, $body, $this->signed($outbound['secret'], $body))->assertStatus(401);
    }

    public function test_permissions_plan_and_workspace_isolation_are_enforced_by_the_server(): void
    {
        [$mine] = $this->endpoint();
        [$foreign] = $this->endpoint($this->b, 'Ajeno');

        $this->actAsMember($this->a, 'lector_in');
        $this->get('/integrations')->assertInertia(fn (AssertableInertia $page) => $page->has('inboundWebhooks.endpoints', 1));
        $this->getJson("/integrations/webhooks/inbound/{$mine->id}/events")->assertOk();
        $this->post('/integrations/webhooks/inbound', ['name' => 'X'])->assertForbidden();
        $this->post("/integrations/webhooks/inbound/{$mine->id}/rotate")->assertForbidden();
        $this->delete("/integrations/webhooks/inbound/{$mine->id}")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actAsMember($this->a);
        $this->getJson("/integrations/webhooks/inbound/{$foreign->id}/events")->assertNotFound();
        $this->post("/integrations/webhooks/inbound/{$foreign->id}/rotate")->assertNotFound();
        $this->delete("/integrations/webhooks/inbound/{$foreign->id}")->assertNotFound();
        $this->put("/integrations/webhooks/inbound/{$foreign->id}", ['name' => 'Robado'])->assertNotFound();
        $this->assertSame('Ajeno', $foreign->fresh()->name);

        $this->post('/integrations/webhooks/inbound', ['name' => 'Mi sistema'])->assertSessionHasErrors('name');
        $this->contractModules($this->a, ['dashboard']);
        $this->post('/integrations/webhooks/inbound', ['name' => 'Otro'])->assertForbidden();
    }

    public function test_the_other_actions_work_and_the_history_is_paginated_and_pruned(): void
    {
        [$endpoint, $secret] = $this->endpoint();
        foreach (range(1, 17) as $i) {
            $body = '{"type":"t"}';
            $this->receive($endpoint->fresh(), $body, $this->signed($secret, $body, null, sprintf('evt-%08d', $i)))->assertStatus(202);
        }
        $this->actAsMember($this->a);

        $this->assertSame([17, 15, 2], [$this->getJson("/integrations/webhooks/inbound/{$endpoint->id}/events")->json('meta.total'), count($this->getJson("/integrations/webhooks/inbound/{$endpoint->id}/events")->json('events')), $this->getJson("/integrations/webhooks/inbound/{$endpoint->id}/events")->json('meta.last_page')]);
        $this->assertCount(2, $this->getJson("/integrations/webhooks/inbound/{$endpoint->id}/events?page=2")->json('events'));

        $this->post("/integrations/webhooks/inbound/{$endpoint->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertFalse($endpoint->fresh()->is_active);
        $this->post("/integrations/webhooks/inbound/{$endpoint->id}/activate");
        $this->put("/integrations/webhooks/inbound/{$endpoint->id}", ['name' => 'Renombrado'])->assertSessionHasNoErrors();
        $this->assertSame('Renombrado', $endpoint->fresh()->name);

        InboundEvent::query()->limit(5)->update(['created_at' => now()->subDays(40)]);
        Artisan::call('webhooks:prune');
        $this->assertSame(12, InboundEvent::count());

        $this->delete("/integrations/webhooks/inbound/{$endpoint->id}")->assertSessionHasNoErrors();
        $this->assertSame([0, 0], [InboundEndpoint::count(), InboundEvent::count()]);
    }

    public function test_without_the_tables_nothing_breaks(): void
    {
        Schema::drop('inbound_events');
        Schema::drop('inbound_endpoints');
        InboundWebhooks::forgetReady();
        $this->actAsMember($this->a);

        $this->get('/integrations')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('inboundWebhooks.enabled', false));
        $this->post('/integrations/webhooks/inbound', ['name' => 'X'])->assertSessionHasErrors('name');
        $this->postJson('/api/webhooks/in/'.str_repeat('b', 48), [])->assertStatus(404);
    }
}

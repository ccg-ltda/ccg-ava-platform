<?php

namespace Tests\Feature;

use App\Integrations\IntegrationRegistry;
use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use App\Reports\ReportPeriod;
use App\Services\ChannelCatalog;
use App\Services\DashboardMetrics;
use App\Services\WorkspaceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Dashboard: the executive and operational summary. Every figure must come from the stored records of the Workspace
 * in scope, the filters must change them, an empty Workspace must show real zeros, a failing section must show an
 * error (never a zero), and nobody may read the figures of another Workspace.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(string $role = 'admin', bool $superuser = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => $superuser])->save();
        $this->actingAsWorkspaceMember($user, $role);

        return $user;
    }

    private function otherWorkspace(string $code = 'OTHER_WS'): Workspace
    {
        return Workspace::create(['organization_id' => Organization::firstOrFail()->id, 'code' => $code, 'name' => "Otro {$code}"]);
    }

    /** One conversation with its messages: `$in` from the contact and `$out` replies (`ai` or `agent`), all at `$at`. */
    private function conversation(Workspace $workspace, string $contact, string $channel = 'whatsapp', string $handling = 'ai', ?CarbonImmutable $at = null, int $in = 1, int $out = 0, string $sender = 'ai', ?Chatbot $bot = null, ?string $demo = null): Conversation
    {
        $at ??= CarbonImmutable::now();
        $bot ??= Chatbot::where('workspace_id', $workspace->id)->first() ?? $workspace->chatbots()->create(['name' => 'Asistente']);
        $conversation = $bot->conversations()->create(['workspace_id' => $workspace->id, 'channel' => $channel, 'contact_id' => $contact, 'contact_name' => $contact, 'demo_key' => $demo]);
        $conversation->forceFill(['handling' => $handling, 'created_at' => $at, 'last_message_at' => $at])->save();

        for ($i = 0; $i < $in; $i++) {
            $conversation->messages()->create(['workspace_id' => $workspace->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Hola', 'sent_at' => $at]);
        }

        for ($i = 0; $i < $out; $i++) {
            $conversation->messages()->create(['workspace_id' => $workspace->id, 'direction' => 'out', 'sender' => $sender, 'type' => 'text', 'body' => 'Respuesta', 'status' => 'sent', 'sent_at' => $at]);
        }

        return $conversation;
    }

    private function props(string $url = '/dashboard'): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    // --- access ---------------------------------------------------------------------------------------------------------

    public function test_the_dashboard_loads_for_authorized_users_and_is_not_reportes(): void
    {
        $this->actAs();

        $this->get('/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard/Index')->has('kpis.data')->has('activity.data.points')->has('states.data')->has('channels.data')->where('kpis.error', null));
        $this->get('/reports')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/Index')->has('stats'));
    }

    public function test_permissions_and_redirects_are_kept(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->seedRoleCatalog();
        Role::findOrCreate('sin-dashboard', 'web')->syncPermissions(['manage-settings']);
        $this->actAs('sin-dashboard');

        $this->get('/dashboard')->assertForbidden();
        $this->get('/reports')->assertForbidden();
        $this->get('/settings')->assertOk();
    }

    public function test_the_menu_has_both_entries_with_their_own_routes_and_permissions(): void
    {
        $menu = file_get_contents(resource_path('js/config/navigation.js'));

        $this->assertStringContainsString("label: 'Dashboard', route: 'dashboard', icon: LayoutDashboard, permission: 'view-dashboard'", $menu);
        $this->assertStringContainsString("label: 'Reportes', route: 'reports.index', icon: BarChart3, permission: 'view-dashboard'", $menu);
    }

    // --- the figures match the records ----------------------------------------------------------------------------------

    public function test_the_figures_match_the_stored_records_and_ignore_what_is_outside_the_period(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $bot = $workspace->chatbots()->create(['name' => 'Uno']);
        $workspace->chatbots()->create(['name' => 'Dos', 'is_active' => false]);
        $this->conversation($workspace, '573001', 'whatsapp', 'ai', null, 2, 1, 'ai', $bot);
        $this->conversation($workspace, '573002', 'web', 'pending', CarbonImmutable::now()->subDays(3), 1, 2, 'agent', $bot);
        $this->conversation($workspace, '573003', 'whatsapp', 'resolved', CarbonImmutable::now()->subDays(5), 3, 0, 'ai', $bot);
        // Outside the 30 days (but inside the 30 before them) and further back still.
        $this->conversation($workspace, '573004', 'web', 'human', CarbonImmutable::now()->subDays(45), 1, 1, 'ai', $bot);
        $this->conversation($workspace, '573005', 'web', 'ai', CarbonImmutable::now()->subDays(200), 4, 4, 'ai', $bot);

        $kpis = $this->props()['kpis']['data'];

        $this->assertSame(3, $kpis['conversations']['value']);
        $this->assertSame(1, $kpis['conversations']['previous']);
        $this->assertSame(200, $kpis['conversations']['change']);
        $this->assertSame(6, $kpis['messagesIn']);                       // 2 + 1 + 3 received in the period
        $this->assertSame(9, $kpis['messages']['value']);                 // + 3 replies
        $this->assertSame(2, $kpis['messages']['previous']);              // the 45 days old pair
        $this->assertSame(1, $kpis['byAi']);
        $this->assertSame(2, $kpis['byAgents']);
        $this->assertSame(4, $kpis['open']);                               // everything but the resolved one, whatever its date
        $this->assertSame(1, $kpis['pending']);
        $this->assertSame(['total' => 2, 'active' => 1], $kpis['assistants']);
        $this->assertSame(1, $kpis['users']);
    }

    public function test_there_is_no_change_without_a_previous_period_to_compare(): void
    {
        $this->actAs();
        $this->conversation(Workspace::first(), '573001');

        $conversations = $this->props()['kpis']['data']['conversations'];

        $this->assertSame([1, 0, null], [$conversations['value'], $conversations['previous'], $conversations['change']]);
    }

    public function test_the_series_add_up_to_the_records_and_follow_the_period_and_its_grouping(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $this->conversation($workspace, '573001', 'whatsapp', 'ai', CarbonImmutable::now(), 2, 1);
        $this->conversation($workspace, '573002', 'whatsapp', 'ai', CarbonImmutable::now()->subDays(2), 1, 2);
        $this->conversation($workspace, '573003', 'whatsapp', 'ai', CarbonImmutable::now()->subDays(20), 1, 0);
        $this->conversation($workspace, '573004', 'whatsapp', 'ai', CarbonImmutable::now()->subDays(60), 5, 5);

        $totals = fn (array $props) => collect($props['activity']['data']['points'])->reduce(fn ($carry, $point) => [
            'conversations' => $carry['conversations'] + $point['values']['conversations'],
            'received' => $carry['received'] + $point['values']['received'],
            'sent' => $carry['sent'] + $point['values']['sent'],
        ], ['conversations' => 0, 'received' => 0, 'sent' => 0]);

        $week = $this->props('/dashboard?period=7d');
        $month = $this->props('/dashboard?period=30d');
        $quarter = $this->props('/dashboard?period=90d');

        $this->assertSame(['conversations' => 2, 'received' => 3, 'sent' => 3], $totals($week));
        $this->assertSame(['conversations' => 3, 'received' => 4, 'sent' => 3], $totals($month));
        $this->assertSame(['conversations' => 4, 'received' => 9, 'sent' => 8], $totals($quarter));
        $this->assertSame(7, count($week['activity']['data']['points']));
        $this->assertSame(30, count($month['activity']['data']['points']));
        $this->assertSame('day', $month['range']['granularity']);
        $this->assertSame('week', $quarter['range']['granularity']);
        $this->assertSame($totals($month)['received'] + $totals($month)['sent'] + $totals($month)['conversations'], $month['activity']['data']['total']);
        // Today's bucket holds today's activity.
        $today = collect($month['activity']['data']['points'])->last();
        $this->assertSame([1, 2, 1], [$today['values']['conversations'], $today['values']['received'], $today['values']['sent']]);
    }

    public function test_the_states_and_channels_distributions_follow_the_period(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $this->conversation($workspace, '573001', 'whatsapp', 'ai');
        $this->conversation($workspace, '573002', 'whatsapp', 'human');
        $this->conversation($workspace, '573003', 'web', 'human');
        $this->conversation($workspace, '573004', 'web', 'resolved', CarbonImmutable::now()->subDays(60));

        $month = $this->props('/dashboard?period=30d');
        $quarter = $this->props('/dashboard?period=90d');

        $this->assertSame(['ai' => 1, 'pending' => 0, 'human' => 2, 'resolved' => 0], collect($month['states']['data'])->pluck('value', 'key')->all());
        $this->assertSame(['ai' => 1, 'pending' => 0, 'human' => 2, 'resolved' => 1], collect($quarter['states']['data'])->pluck('value', 'key')->all());
        $this->assertSame(['whatsapp' => 2, 'web' => 1], collect($month['channels']['data'])->pluck('value', 'key')->all());
        $this->assertSame(['WhatsApp', 'Web'], collect($month['channels']['data'])->pluck('label')->all());
        $this->assertSame(['whatsapp' => 2, 'web' => 2], collect($quarter['channels']['data'])->pluck('value', 'key')->all());
    }

    public function test_an_empty_workspace_shows_real_zeros_and_no_errors(): void
    {
        $this->actAs();
        $other = $this->otherWorkspace();
        $this->conversation($other, '999', 'whatsapp', 'ai', null, 5, 5);

        $props = $this->props();

        $this->assertNull($props['kpis']['error']);
        $this->assertSame(0, $props['kpis']['data']['conversations']['value']);
        $this->assertSame(0, $props['kpis']['data']['open']);
        $this->assertSame(0, $props['activity']['data']['total']);
        $this->assertSame([0, 0, 0, 0], collect($props['states']['data'])->pluck('value')->all());
        $this->assertSame([], $props['channels']['data']);
        $this->assertSame([], $props['recentConversations']['data']);
        $this->assertSame([], $props['recentActivity']['data']);
        $this->assertSame(['pending' => 0, 'unconfirmed' => 0, 'failedExecutions' => 0], $props['attention']['data']);
        $this->assertSame(0, $props['demo']['data']);
    }

    // --- errors are never zeros -----------------------------------------------------------------------------------------

    public function test_a_section_that_cannot_be_read_is_an_error_and_not_a_zero_and_leaks_nothing(): void
    {
        $this->actAs();
        $this->conversation(Workspace::first(), '573001');
        $entries = [];
        Log::listen(function (MessageLogged $event) use (&$entries) {
            $entries[] = $event->message.json_encode($event->context);
        });
        // The service with one section that cannot be read (what a failing query would do).
        $this->app->bind(DashboardMetrics::class, fn ($app) => new class($app->make(ChannelCatalog::class), $app->make(WorkspaceReport::class), $app->make(IntegrationRegistry::class)) extends DashboardMetrics
        {
            public function series(?Workspace $target, ReportPeriod $period, WorkspaceSetting $settings): array
            {
                throw new \RuntimeException('SQLSTATE secret-contact-573001');
            }
        });

        $props = $this->props();

        $this->assertNull($props['activity']['data']);
        $this->assertNotNull($props['activity']['error']);
        $this->assertStringNotContainsString('secret-contact', $props['activity']['error']);
        $this->assertNull($props['kpis']['error']);
        $this->assertSame(1, $props['kpis']['data']['conversations']['value']);
        $this->assertNotEmpty(array_filter($entries, fn ($line) => str_contains($line, 'dashboard_section_failed')));
        foreach ($entries as $line) {
            $this->assertStringNotContainsString('secret-contact', $line);
        }
    }

    // --- Workspace isolation --------------------------------------------------------------------------------------------

    public function test_the_data_of_two_workspaces_is_never_mixed(): void
    {
        $this->actAs();
        $mine = Workspace::first();
        $other = $this->otherWorkspace();
        $botOther = $other->chatbots()->create(['name' => 'Ajeno']);
        $this->conversation($mine, 'mi-contacto', 'whatsapp', 'ai', null, 1, 1);
        $this->conversation($other, 'contacto-ajeno', 'web', 'pending', null, 7, 7, 'agent', $botOther);
        AuditLog::create(['workspace_id' => $other->id, 'workspace_code' => $other->code, 'workspace_name' => $other->name, 'user_id' => null, 'user_name' => 'Persona ajena', 'user_email' => 'a@b.c', 'action' => 'created', 'resource_type' => 'user', 'resource_id' => 1, 'resource_label' => 'x', 'description' => 'Creó algo ajeno', 'changes' => [], 'ip_address' => '1.1.1.1']);
        $other->integrations()->create(['name' => 'Integración ajena', 'type' => 'http', 'is_active' => true, 'config' => ['base_url' => 'https://example.com', 'method' => 'GET', 'endpoint' => '/', 'auth_type' => 'none', 'headers' => [], 'query' => [], 'timeout' => 5], 'secrets' => []]);

        $props = $this->props();
        $json = json_encode($props);

        $this->assertSame(1, $props['kpis']['data']['conversations']['value']);
        $this->assertSame(1, $props['kpis']['data']['messagesIn']);
        $this->assertSame(0, $props['kpis']['data']['pending']);
        $this->assertSame(1, $props['kpis']['data']['assistants']['total']);
        $this->assertSame(['whatsapp' => 1], collect($props['channels']['data'])->pluck('value', 'key')->all());
        foreach (['contacto-ajeno', 'Ajeno', 'Persona ajena', 'Creó algo ajeno', 'Integración ajena', 'OTHER_WS'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, $leak);
        }
    }

    public function test_a_client_cannot_read_another_workspace_by_changing_the_request(): void
    {
        $this->actAs();
        $other = $this->otherWorkspace();
        $this->conversation($other, 'contacto-ajeno', 'web', 'pending', null, 7, 7);

        // `workspace_id` is not an input; `workspace` is refused for whoever may not choose, in every form.
        $this->assertSame(0, $this->props("/dashboard?workspace_id={$other->id}")['kpis']['data']['conversations']['value']);
        foreach ([$other->id, 'all', 'abc', '999999', "{$other->id}x", '-1'] as $value) {
            $status = $this->get("/dashboard?workspace={$value}")->status();
            $this->assertContains($status, [403, 302, 404], "workspace={$value}");
            $this->assertNotSame(200, $status, "workspace={$value}");
        }
        $this->assertFalse($this->props()['scope']['canChoose']);
        $this->post('/dashboard', ['workspace' => $other->id])->assertStatus(405);
    }

    public function test_a_superuser_outside_the_administrative_workspace_is_limited_like_anyone_else(): void
    {
        $this->actAs('admin', superuser: true);
        $other = $this->otherWorkspace();

        $this->get("/dashboard?workspace={$other->id}")->assertForbidden();
        $this->get('/dashboard?workspace=all')->assertForbidden();
        $this->assertFalse($this->props()['scope']['canChoose']);
    }

    public function test_an_authorized_administrator_chooses_one_workspace_or_all(): void
    {
        $this->actAs('admin', superuser: true);
        config(['workspace.admin_code' => 'TEST_WS']);
        $mine = Workspace::first();
        $other = $this->otherWorkspace();
        $this->conversation($mine, 'mio', 'whatsapp', 'ai', null, 1, 1);
        $this->conversation($other, 'ajeno-1', 'web', 'pending', null, 2, 0);
        $this->conversation($other, 'ajeno-2', 'web', 'human', null, 2, 0);

        $own = $this->props();
        $one = $this->props("/dashboard?workspace={$other->id}");
        $all = $this->props('/dashboard?workspace=all');
        $self = $this->props("/dashboard?workspace={$mine->id}");

        $this->assertTrue($own['scope']['canChoose']);
        $this->assertSame(['active', 1], [$own['scope']['mode'], $own['kpis']['data']['conversations']['value']]);
        $this->assertSame(['workspace', 'OTHER_WS', 2, 4, 1], [$one['scope']['mode'], $one['scope']['workspace']['code'], $one['kpis']['data']['conversations']['value'], $one['kpis']['data']['messagesIn'], $one['kpis']['data']['pending']]);
        $this->assertSame(['active', 1], [$self['scope']['mode'], $self['kpis']['data']['conversations']['value']]);
        $this->assertSame(['all', null, 3], [$all['scope']['mode'], $all['scope']['workspace'], $all['kpis']['data']['conversations']['value']]);
        $this->assertSame(['ajeno-1', 'ajeno-2'], collect($one['recentConversations']['data'])->pluck('contact')->sort()->values()->all());
        $this->assertNull($all['integrations']);
        $this->assertCount(3, $all['recentConversations']['data']);
        $this->assertTrue(collect($all['recentConversations']['data'])->every(fn ($row) => $row['workspace'] !== null));
        $this->get('/dashboard?workspace=999999')->assertNotFound();
        $this->get('/dashboard?workspace=abc')->assertSessionHasErrors('workspace');
    }

    // --- what each role may see -----------------------------------------------------------------------------------------

    public function test_lists_and_details_need_the_permission_of_their_own_module(): void
    {
        $this->actAs('cliente');
        $workspace = Workspace::first();
        $this->conversation($workspace, 'quien-escribe', 'whatsapp', 'pending');
        $workspace->integrations()->create(['name' => 'Web', 'type' => 'web', 'is_active' => true, 'config' => ['webhook_url' => 'https://n8n.example.com/hook', 'allowed_origins' => []], 'secrets' => ['webhook_secret' => 'super-secreto']]);

        // `cliente` only has `view-dashboard`: aggregated figures, nothing that identifies a conversation, an event or a connection.
        $client = $this->props();
        $this->assertSame(1, $client['kpis']['data']['conversations']['value']);
        $this->assertSame(['conversations' => false, 'assistants' => false, 'settings' => false], $client['can']);
        foreach (['attention', 'integrations', 'recentConversations', 'recentActivity'] as $section) {
            $this->assertNull($client[$section], $section);
        }
        $this->assertStringNotContainsString('quien-escribe', json_encode($client));

        $this->actAs('agente');
        $agent = $this->props();
        $this->assertNotNull($agent['recentConversations']);
        $this->assertSame(1, $agent['attention']['data']['pending']);
        $this->assertArrayNotHasKey('failedExecutions', $agent['attention']['data']);
        $this->assertNull($agent['integrations']);
        $this->assertNull($agent['recentActivity']);

        $this->actAs('admin');
        $admin = $this->props();
        foreach (['attention', 'integrations', 'recentConversations', 'recentActivity'] as $section) {
            $this->assertNotNull($admin[$section], $section);
        }
        $this->assertStringNotContainsString('super-secreto', json_encode($admin));
    }

    // --- operational sections -------------------------------------------------------------------------------------------

    public function test_attention_counts_what_waits_for_a_person(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $bot = $workspace->chatbots()->create(['name' => 'Uno']);
        $first = $this->conversation($workspace, '573001', 'whatsapp', 'pending', null, 1, 1, 'ai', $bot);
        $this->conversation($workspace, '573002', 'whatsapp', 'pending', CarbonImmutable::now()->subDays(100), 1, 0, 'ai', $bot);
        $first->messages()->where('direction', 'out')->update(['status' => 'unconfirmed']);
        $inbound = $first->messages()->where('direction', 'in')->firstOrFail();
        foreach (['failed' => 1, 'succeeded' => 0] as $status => $count) {
            if ($count) {
                DB::table('chatbot_executions')->insert([
                    'correlation_id' => (string) str()->uuid(), 'workspace_id' => $workspace->id, 'chatbot_id' => $bot->id, 'conversation_id' => $first->id, 'message_id' => $inbound->id,
                    'workflow_key' => 'x', 'n8n_workflow_id' => 'x', 'handling_version' => 0, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $this->assertSame(['pending' => 2, 'unconfirmed' => 1, 'failedExecutions' => 1], $this->props()['attention']['data']);
        $this->assertSame(0, ChatbotExecution::where('status', 'succeeded')->count());
    }

    public function test_integrations_report_only_what_their_last_test_proves_and_never_secrets(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $make = fn (string $name, array $attributes) => $workspace->integrations()->create(array_merge(['name' => $name, 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => '123456'], 'secrets' => ['access_token' => 'TOKEN-PRIVADO-XYZ']], $attributes));
        $make('Verificada', ['last_tested_at' => now(), 'last_test_ok' => true]);
        $make('Fallida', ['last_tested_at' => now(), 'last_test_ok' => false]);
        $make('Sin probar', []);
        $make('Apagada', ['is_active' => false, 'last_tested_at' => now(), 'last_test_ok' => true]);
        $workspace->integrations()->create(['name' => 'Canal web', 'type' => 'web', 'is_active' => true, 'config' => ['webhook_url' => 'https://n8n.example.com/hook', 'allowed_origins' => []], 'secrets' => ['webhook_secret' => 'OTRO-SECRETO']]);

        $props = $this->props();
        $states = collect($props['integrations']['data'])->pluck('state', 'name')->all();

        $this->assertSame(['Apagada' => 'inactive', 'Canal web' => 'configured', 'Fallida' => 'failed', 'Sin probar' => 'untested', 'Verificada' => 'verified'], $states);
        $this->assertStringNotContainsString('TOKEN-PRIVADO-XYZ', json_encode($props));
        $this->assertStringNotContainsString('OTRO-SECRETO', json_encode($props));
    }

    public function test_recent_activity_is_the_audit_log_of_the_workspace_in_scope(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        foreach (range(1, 8) as $number) {
            AuditLog::create(['workspace_id' => $workspace->id, 'workspace_code' => $workspace->code, 'workspace_name' => $workspace->name, 'user_id' => null, 'user_name' => 'Ana', 'user_email' => 'a@b.c', 'action' => 'updated', 'resource_type' => 'user', 'resource_id' => $number, 'resource_label' => "r{$number}", 'description' => "Cambio {$number}", 'changes' => [], 'ip_address' => '1.1.1.1']);
        }

        $items = $this->props()['recentActivity']['data'];

        $this->assertCount(6, $items);
        $this->assertSame('Cambio 8', $items[0]['description']);
        $this->assertArrayNotHasKey('ip', $items[0]);
        $this->assertArrayNotHasKey('changes', $items[0]);
    }

    public function test_demo_conversations_are_counted_and_announced(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $this->conversation($workspace, 'real', 'whatsapp');
        $this->conversation($workspace, 'simulada', 'whatsapp', 'ai', null, 1, 0, 'ai', null, 'demo_uno');

        $props = $this->props();

        $this->assertSame(1, $props['demo']['data']);
        $this->assertSame(2, $props['kpis']['data']['conversations']['value']);
    }

    public function test_the_metrics_service_is_reusable_with_a_given_workspace_and_period(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $other = $this->otherWorkspace();
        $this->conversation($workspace, '1', 'whatsapp', 'ai', null, 2);
        $this->conversation($other, '2', 'whatsapp', 'ai', null, 9);
        $period = ReportPeriod::make('7d', null, 'UTC');

        $metrics = app(DashboardMetrics::class);

        $this->assertSame(2, $metrics->kpis($workspace, $period)['messagesIn']);
        $this->assertSame(9, $metrics->kpis($other, $period)['messagesIn']);
        $this->assertSame(11, $metrics->kpis(null, $period)['messagesIn']);
    }

    public function test_a_role_with_assistants_but_not_conversations_only_gets_the_executions_signal(): void
    {
        $this->seedRoleCatalog();
        Role::findOrCreate('solo-asistentes', 'web')->syncPermissions(['view-dashboard', 'view-chatbots']);
        $this->actAs('solo-asistentes');
        $this->conversation(Workspace::first(), 'contacto-reservado', 'whatsapp', 'pending');

        $props = $this->props();

        $this->assertSame(['failedExecutions' => 0], $props['attention']['data']);
        $this->assertNull($props['recentConversations']);
        $this->assertNull($props['integrations']);
        $this->assertSame(['conversations' => false, 'assistants' => true, 'settings' => false], $props['can']);
        $this->assertStringNotContainsString('contacto-reservado', json_encode($props));
    }

    public function test_a_failing_headline_section_is_an_error_and_not_zeros(): void
    {
        $this->actAs();
        $this->conversation(Workspace::first(), '573001');
        $this->app->bind(DashboardMetrics::class, fn ($app) => new class($app->make(ChannelCatalog::class), $app->make(WorkspaceReport::class), $app->make(IntegrationRegistry::class)) extends DashboardMetrics
        {
            public function kpis(?Workspace $target, ReportPeriod $period): array
            {
                throw new \RuntimeException('boom');
            }
        });

        $props = $this->props();

        $this->assertNull($props['kpis']['data']);
        $this->assertNotNull($props['kpis']['error']);
        $this->assertNull($props['activity']['error']);
        $this->assertSame(2, $props['activity']['data']['total']); // the conversation and its message, still there
    }

    public function test_the_twelve_month_period_groups_by_month_and_an_invalid_period_is_refused(): void
    {
        $this->actAs();
        $this->conversation(Workspace::first(), '573001', 'whatsapp', 'ai', CarbonImmutable::now()->subMonths(5), 2, 1);

        $year = $this->props('/dashboard?period=12m');

        $this->assertSame('month', $year['range']['granularity']);
        $this->assertGreaterThanOrEqual(12, count($year['activity']['data']['points']));
        $this->assertSame(1, $year['kpis']['data']['conversations']['value']);
        $this->assertSame(4, $year['activity']['data']['total']); // 1 conversation + 2 received + 1 reply
        $this->get('/dashboard?period=forever')->assertSessionHasErrors('period');
    }

    public function test_the_number_of_queries_does_not_grow_with_the_amount_of_data(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $queries = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/dashboard')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $this->conversation($workspace, 'uno', 'whatsapp', 'ai', null, 1, 1);
        $few = $queries();

        foreach (range(2, 25) as $number) {
            $this->conversation($workspace, "c{$number}", $number % 2 ? 'web' : 'whatsapp', ['ai', 'pending', 'human', 'resolved'][$number % 4], CarbonImmutable::now()->subDays($number), 3, 2);
        }
        $workspace->integrations()->create(['name' => 'Web', 'type' => 'web', 'is_active' => true, 'config' => ['webhook_url' => 'https://n8n.example.com/hook', 'allowed_origins' => []], 'secrets' => []]);
        $many = $queries();

        $this->assertSame($few, $many, 'More rows must not mean more queries.');
        $this->assertLessThan(45, $many);
    }
}

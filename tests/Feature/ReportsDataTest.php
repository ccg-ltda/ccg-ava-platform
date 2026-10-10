<?php

namespace Tests\Feature;

use App\Integrations\IntegrationRegistry;
use App\Models\Chatbot;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Reportes with real data: the figures must match the stored records of the Workspace in scope, follow the period,
 * agree with the Dashboard (same calculations), never leak another Workspace and expose people only to who may see them.
 * Every test uses at least two Workspaces; only rows created by the test exist.
 */
class ReportsDataTest extends TestCase
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

    private function conversation(Workspace $workspace, string $contact, string $channel = 'whatsapp', ?CarbonImmutable $at = null, int $in = 1, int $out = 0, string $sender = 'ai', string $handling = 'ai'): Conversation
    {
        $at ??= CarbonImmutable::now();
        $bot = Chatbot::where('workspace_id', $workspace->id)->first() ?? $workspace->chatbots()->create(['name' => 'Asistente']);
        $conversation = $bot->conversations()->create(['workspace_id' => $workspace->id, 'channel' => $channel, 'contact_id' => $contact, 'contact_name' => $contact]);
        $conversation->forceFill(['handling' => $handling, 'created_at' => $at, 'last_message_at' => $at])->save();

        for ($i = 0; $i < $in; $i++) {
            $conversation->messages()->create(['workspace_id' => $workspace->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Hola', 'sent_at' => $at]);
        }

        for ($i = 0; $i < $out; $i++) {
            $conversation->messages()->create(['workspace_id' => $workspace->id, 'direction' => 'out', 'sender' => $sender, 'type' => 'text', 'body' => 'Respuesta', 'status' => 'sent', 'sent_at' => $at]);
        }

        return $conversation;
    }

    private function props(string $url = '/reports'): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    /** The two Workspaces with known data: A (the session's) and B (foreign, with a lot more of everything). */
    private function twoWorkspaces(): array
    {
        $a = Workspace::first();
        $b = $this->otherWorkspace();
        $this->conversation($a, '573001', 'whatsapp', null, 2, 1, 'ai');
        $this->conversation($a, '573002', 'web', CarbonImmutable::now()->subDays(3), 1, 2, 'agent', 'pending');
        $this->conversation($a, '573003', 'web', CarbonImmutable::now()->subDays(45), 1, 1);
        $this->conversation($a, '573004', 'web', CarbonImmutable::now()->subDays(200), 5, 5);
        for ($i = 0; $i < 6; $i++) {
            $this->conversation($b, "57400{$i}", 'whatsapp', null, 3, 3);
        }

        return [$a, $b];
    }

    public function test_the_figures_match_the_stored_records_of_the_workspace(): void
    {
        $this->actAs();
        $this->twoWorkspaces();

        $props = $this->props('/reports?period=30d');

        // 30 days: 2 conversations; messages: in 2+1 = 3, out 1+2 = 3 → 6 interactions. The 45- and 200-day rows are out.
        $this->assertSame(2, $props['figures']['data']['chats']['value']);
        $this->assertSame(6, $props['figures']['data']['interactions']['value']);
        // The 30 days before them hold the 45-day-old conversation (1 in + 1 out).
        $this->assertSame(1, $props['figures']['data']['chats']['previous']);
        $this->assertSame(2, $props['figures']['data']['interactions']['previous']);
        $this->assertSame(100, $props['figures']['data']['chats']['change']);
        $this->assertSame(200, $props['figures']['data']['interactions']['change']);

        $rows = collect($props['figures']['data']['rows'])->keyBy('key');
        $this->assertSame([2, 3, 3, 1, 2], [$rows['conversations']['value'], $rows['received']['value'], $rows['sent']['value'], $rows['ai']['value'], $rows['agents']['value']]);

        // The series add up to the same totals as the figures (no bucket lost, none counted twice).
        $this->assertSame(2 + 3 + 3, $props['activity']['data']['total']);
        $this->assertSame(2, collect($props['activity']['data']['points'])->sum('values.conversations'));
        $this->assertSame(3, collect($props['activity']['data']['points'])->sum('values.received'));

        $this->assertSame(['whatsapp' => 1, 'web' => 1], collect($props['channels']['data'])->pluck('value', 'key')->all());
        $this->assertSame(2, collect($props['states']['data'])->sum('value'));
        $this->assertSame(2, $props['chatHours']['data']['total']);
        $this->assertSame(3, $props['messageHours']['data']['total']);
        $this->assertCount(24, $props['chatHours']['data']['points']);
    }

    public function test_the_period_filter_changes_the_figures(): void
    {
        $this->actAs();
        $this->twoWorkspaces();

        $seven = $this->props('/reports?period=7d')['figures']['data'];
        $ninety = $this->props('/reports?period=90d')['figures']['data'];
        $year = $this->props('/reports?period=12m')['figures']['data'];

        $this->assertSame([2, 3, 4], [$seven['chats']['value'], $ninety['chats']['value'], $year['chats']['value']]);
        $this->assertSame([6, 8, 18], [$seven['interactions']['value'], $ninety['interactions']['value'], $year['interactions']['value']]);
    }

    public function test_the_granularity_regroups_without_changing_the_totals(): void
    {
        $this->actAs();
        $this->twoWorkspaces();

        $byDay = $this->props('/reports?period=90d&granularity=day')['activity']['data'];
        $byWeek = $this->props('/reports?period=90d&granularity=week')['activity']['data'];
        $byMonth = $this->props('/reports?period=90d&granularity=month')['activity']['data'];

        $this->assertCount(90, $byDay['points']);
        $this->assertGreaterThan(count($byMonth['points']), count($byWeek['points']));
        $this->assertSame([$byDay['total']], [$byWeek['total']]);
        $this->assertSame($byDay['total'], $byMonth['total']);
        $this->assertSame(3, collect($byMonth['points'])->sum('values.conversations'));
    }

    public function test_reportes_and_the_dashboard_agree_on_the_same_numbers(): void
    {
        $this->actAs();
        $this->twoWorkspaces();

        $report = $this->props('/reports?period=30d');
        $dashboard = $this->props('/dashboard?period=30d');

        $this->assertSame($dashboard['kpis']['data']['conversations'], $report['figures']['data']['chats']);
        $this->assertSame($dashboard['kpis']['data']['messages'], $report['figures']['data']['interactions']);
        $this->assertSame($dashboard['activity']['data'], $report['activity']['data']);
        $this->assertSame($dashboard['states']['data'], $report['states']['data']);
        $this->assertSame($dashboard['channels']['data'], $report['channels']['data']);
    }

    public function test_an_empty_workspace_shows_real_zeros_and_empty_series(): void
    {
        $this->actAs('admin', superuser: true);
        $this->twoWorkspaces();
        $empty = $this->otherWorkspace('EMPTY_WS');
        config(['workspace.admin_code' => 'TEST_WS']);

        $props = $this->props("/reports?workspace={$empty->id}");

        $this->assertSame(0, $props['figures']['data']['chats']['value']);
        $this->assertNull($props['figures']['data']['chats']['change']);
        $this->assertSame(0, $props['activity']['data']['total']);
        $this->assertSame([], $props['channels']['data']);
        $this->assertSame(0, $props['chatHours']['data']['total']);
        $this->assertNull($props['figures']['error']);
    }

    public function test_hours_follow_the_workspace_timezone(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $workspace->settings()->create(array_merge(WorkspaceSetting::defaults(), ['timezone' => 'America/Bogota']));
        // 15:30 UTC is 10:30 in Bogotá (UTC-5).
        $this->conversation($workspace, '573009', 'web', CarbonImmutable::now('UTC')->subDay()->setTime(15, 30));

        $hours = collect($this->props('/reports')['chatHours']['data']['points']);

        $this->assertSame(1, $hours->firstWhere('key', '10')['value']);
        $this->assertSame(0, $hours->firstWhere('key', '15')['value']);
    }

    public function test_another_workspace_never_appears_in_any_section(): void
    {
        $this->actAs();
        [, $b] = $this->twoWorkspaces();
        $this->conversation($b, '573999', 'telegram', null, 9, 9);

        $encoded = json_encode($this->props('/reports?period=12m'));

        $this->assertStringNotContainsString('573999', $encoded);
        $this->assertStringNotContainsString('telegram', $encoded);
        $this->assertStringNotContainsString($b->name, $encoded);
        $this->assertSame(4, $this->props('/reports?period=12m')['figures']['data']['chats']['value']);
    }

    public function test_a_workspace_sent_by_the_browser_is_never_trusted(): void
    {
        $this->actAs();
        [, $b] = $this->twoWorkspaces();

        $this->get("/reports?workspace={$b->id}")->assertForbidden();
        $this->get('/reports?workspace=all')->assertForbidden();
        $this->get("/reports?workspace_id={$b->id}&period=12m")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scope.mode', 'active')->where('figures.data.chats.value', 4));
    }

    public function test_a_superuser_only_chooses_inside_the_administrative_workspace(): void
    {
        $this->actAs('admin', superuser: true);
        [$a, $b] = $this->twoWorkspaces();

        // Outside the administrative Workspace the superuser is a normal member: the foreign data stays unreachable.
        $this->get("/reports?workspace={$b->id}")->assertForbidden();

        config(['workspace.admin_code' => $a->code]);
        $this->assertSame(6, $this->props("/reports?workspace={$b->id}&period=30d")['figures']['data']['chats']['value']);
        $this->assertSame(8, $this->props('/reports?workspace=all&period=30d')['figures']['data']['chats']['value']);
        $this->assertSame(2, $this->props('/reports?period=30d')['figures']['data']['chats']['value']);
    }

    public function test_the_people_of_the_workspace_are_shown_only_to_who_may_view_users(): void
    {
        $this->seedRoleCatalog();
        $this->actAs('cliente');
        $colleague = User::factory()->create();
        Workspace::first()->users()->attach($colleague->id, ['role' => 'agente']);

        $props = $this->props();
        $this->assertNull($props['recentUsers']);
        $this->assertStringNotContainsString($colleague->email, json_encode($props));
        $this->assertStringNotContainsString($colleague->name, json_encode($props));
        // The aggregates remain available to the permission of the page.
        $this->assertSame(2, $props['stats']['users']);

        $this->actAs('supervisor');
        $this->assertStringContainsString($colleague->email, json_encode($this->props()['recentUsers']));

        $this->actAs('agente');
        $this->assertNull($this->props()['recentUsers']);
    }

    public function test_a_failing_section_is_an_error_and_never_a_zero(): void
    {
        $this->actAs();
        $this->twoWorkspaces();
        Log::spy();
        $this->app->bind(DashboardMetrics::class, fn ($app) => new class($app->make(ChannelCatalog::class), $app->make(WorkspaceReport::class), $app->make(IntegrationRegistry::class)) extends DashboardMetrics
        {
            public function comparison(?Workspace $target, ReportPeriod $period): array
            {
                throw new \RuntimeException('SQLSTATE secret-contact-573001');
            }
        });

        $response = $this->get('/reports')->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertNull($props['figures']['data']);
        $this->assertNotNull($props['figures']['error']);
        $this->assertStringNotContainsString('secret-contact', json_encode($props));
        $this->assertNull($props['activity']['error']);
        $this->assertGreaterThan(0, $props['activity']['data']['total']);
        Log::shouldHaveReceived('error')->with('report_section_failed', \Mockery::on(fn ($context) => $context === ['section' => 'figures', 'exception' => \RuntimeException::class]))->once();
    }

    public function test_questions_and_surveys_have_no_source_and_no_figure(): void
    {
        $this->actAs();
        $this->twoWorkspaces();

        $metrics = collect($this->props()['metrics'])->keyBy('key');

        $this->assertFalse($metrics['questions']['connected']);
        $this->assertFalse($metrics['surveys']['connected']);
        $this->assertTrue($metrics['chats']['connected']);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_data(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $this->conversation($workspace, '573001');
        $this->get('/reports')->assertOk(); // warms the one-time lookups (permissions, settings)
        DB::enableQueryLog();
        $this->get('/reports')->assertOk();
        $small = count(DB::getQueryLog());

        for ($i = 0; $i < 25; $i++) {
            $this->conversation($workspace, "5731{$i}", 'web', CarbonImmutable::now()->subDays($i % 20), 3, 3);
        }
        DB::flushQueryLog();
        $this->get('/reports')->assertOk();

        $this->assertSame($small, count(DB::getQueryLog()));
    }
}

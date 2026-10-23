<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use App\Services\DemoBusinessData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Dashboard and Reportes only build, query and send what the plan of the Workspace includes: a Workspace with only Agenda sees Agenda figures and nothing of
 * conversations, assistants or the team (not a zero, not an empty panel), and the server does not even read those tables. The role still limits it above.
 */
class ReportsModuleAvailabilityTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const CONVERSATION_TABLES = ['conversations', 'messages', 'chatbots', 'chatbot_executions'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['workspace.admin_code' => 'DESARROLLO_DEV']);
        $this->seedRoleCatalog();
    }

    private function signIn(Workspace $workspace): void
    {
        $this->actingAs($this->member($workspace))->withSession(['workspace_id' => $workspace->id]);
    }

    /** @return array{0: array<string, mixed>, 1: list<string>} the page props and the tables the request read */
    private function visit(string $url): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $props = $this->get($url)->assertOk()->viewData('page')['props'];
        $tables = collect(DB::getQueryLog())->flatMap(fn (array $query) => preg_match_all('/(?:from|join)\s+"([a-z_]+)"/i', $query['query'], $matches) ? $matches[1] : [])->unique()->values()->all();
        DB::disableQueryLog();

        return [$props, $tables];
    }

    private function agendaOnly(): Workspace
    {
        $workspace = $this->bookingWorkspace('SOLO_AGENDA', ['dashboard', 'reports', 'service_catalog', 'professionals', 'bookings']);
        $this->signIn($workspace);

        return $workspace;
    }

    public function test_a_workspace_with_only_agenda_gets_agenda_reports_and_nothing_of_the_other_modules(): void
    {
        $this->agendaOnly();

        [$props, $tables] = $this->visit('/reports');

        $this->assertSame(['conversations' => false, 'team' => false, 'bookings' => true, 'orders' => false, 'customers' => false], $props['sources']);
        $this->assertNotNull($props['operations']['bookings']);
        $this->assertNull($props['operations']['orders']);
        $this->assertNull($props['operations']['customers']);
        foreach (['figures', 'activity', 'channels', 'states', 'chatHours', 'messageHours'] as $conversationSection) {
            $this->assertNull($props[$conversationSection], "[{$conversationSection}] is not sent without the conversations module");
        }
        $this->assertSame([], $props['metrics']);
        $this->assertNull($props['stats'] ?? null);
        $this->assertNull($props['teamGrowth'] ?? null);
        $this->assertContains('appointments', $tables, 'the probe sees the tables a request reads');
        $this->assertSame([], array_values(array_intersect($tables, self::CONVERSATION_TABLES)), 'the conversation tables are not even read');
    }

    public function test_the_dashboard_of_a_workspace_with_only_agenda_is_empty_without_reading_other_modules(): void
    {
        $this->agendaOnly();

        [$props, $tables] = $this->visit('/dashboard');

        $this->assertSame(['conversations' => false, 'assistants' => false, 'users' => false], $props['sources']);
        $this->assertSame([], $props['kpis']['data']);
        foreach (['activity', 'states', 'channels', 'attention', 'integrations', 'recentConversations', 'recentActivity', 'demo'] as $section) {
            $this->assertNull($props[$section], "[{$section}] is not sent");
        }
        $this->assertSame([], array_values(array_intersect($tables, self::CONVERSATION_TABLES)));
    }

    public function test_a_workspace_with_several_modules_keeps_every_panel_of_those_modules(): void
    {
        $workspace = $this->bookingWorkspace('VARIOS', ['dashboard', 'reports', 'conversations', 'assistants', 'users_roles', 'customers', 'orders', 'product_catalog', 'service_catalog', 'professionals', 'bookings']);
        $this->signIn($workspace);

        [$reports] = $this->visit('/reports');
        $this->assertSame(['conversations' => true, 'team' => true, 'bookings' => true, 'orders' => true, 'customers' => true], $reports['sources']);
        $this->assertNotEmpty($reports['metrics']);
        foreach (['bookings', 'orders', 'customers'] as $module) {
            $this->assertNotNull($reports['operations'][$module]);
        }
        $this->assertNotNull($reports['figures']);

        [$dashboard] = $this->visit('/dashboard');
        $this->assertSame(['conversations' => true, 'assistants' => true, 'users' => true], $dashboard['sources']);
        $this->assertArrayHasKey('conversations', $dashboard['kpis']['data']);
        $this->assertArrayHasKey('assistants', $dashboard['kpis']['data']);
        $this->assertNotNull($dashboard['activity']);
    }

    public function test_a_workspace_with_no_reporting_module_gets_a_clean_empty_page(): void
    {
        $workspace = $this->bookingWorkspace('SIN_REPORTES', ['dashboard', 'reports']);
        $this->signIn($workspace);

        [$reports, $tables] = $this->visit('/reports');

        $this->assertSame(['conversations' => false, 'team' => false, 'bookings' => false, 'orders' => false, 'customers' => false], $reports['sources']);
        $this->assertSame([null, null, null], array_values($reports['operations']));
        $this->assertSame([], array_values(array_intersect($tables, [...self::CONVERSATION_TABLES, 'appointments', 'orders', 'customers'])));
    }

    public function test_the_figures_are_the_workspaces_own_and_a_module_it_lacks_never_leaks_from_another_workspace(): void
    {
        $admin = Workspace::create(['organization_id' => Workspace::query()->value('organization_id') ?? Organization::create(['name' => 'CCG'])->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $admin->settings()->create(array_merge(WorkspaceSetting::defaults(), ['timezone' => 'UTC']));
        app(DemoBusinessData::class)->generate($admin);

        $this->agendaOnly();
        [$props] = $this->visit('/reports?period=90d');

        $this->assertSame(0, $props['operations']['bookings']['data']['kpis']['total'], 'the demo appointments of another Workspace do not count here');
        $this->assertNull($props['operations']['orders']);
    }
}

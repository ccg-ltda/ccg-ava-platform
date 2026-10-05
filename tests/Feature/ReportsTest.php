<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Reportes: the Workspace analytics center. No module feeds it yet, so no metric may carry a value. */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(string $role = 'admin', bool $superuser = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => $superuser])->save();
        $this->actingAsWorkspaceMember($user, $role);

        return $user;
    }

    private function join(Workspace $workspace, string $when): User
    {
        $user = User::factory()->create();
        DB::table('workspace_user')->insert(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'cliente', 'created_at' => $when, 'updated_at' => $when]);

        return $user;
    }

    private function otherWorkspace(): Workspace
    {
        return Workspace::create(['organization_id' => Organization::firstOrFail()->id, 'code' => 'OTHER_WS', 'name' => 'Other']);
    }

    public function test_every_metric_is_unconnected_and_has_no_value(): void
    {
        $this->actAs();

        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/Index')->has('metrics', 4)
            ->where('metrics', fn ($metrics) => collect($metrics)->every(fn ($m) => $m['connected'] === false && $m['value'] === null))
            ->where('metrics.0.key', 'chats')->where('metrics.3.key', 'surveys'));
    }

    public function test_the_page_needs_the_view_dashboard_permission(): void
    {
        $this->seedRoleCatalog();
        Role::findOrCreate('sin-reportes', 'web')->syncPermissions(['manage-settings']);
        $this->actAs('sin-reportes');

        $this->get('/dashboard')->assertForbidden();
        $this->get('/settings')->assertOk();
    }

    public function test_the_menu_entry_follows_the_permission(): void
    {
        $this->assertStringContainsString("label: 'Reportes', route: 'dashboard', icon: BarChart3, permission: 'view-dashboard'", file_get_contents(resource_path('js/config/navigation.js')));
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_the_default_period_is_thirty_days_by_day_and_the_selector_offers_every_period(): void
    {
        $this->actAs();

        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters', ['period' => '30d', 'granularity' => 'day'])->where('range.days', 30)
            ->has('options.periods', 4)->where('options.granularities', [['value' => 'day', 'label' => 'Día'], ['value' => 'week', 'label' => 'Semana']])
            ->has('teamGrowth.points', 30));
    }

    public function test_each_period_defines_its_buckets_and_an_unsupported_grouping_falls_back(): void
    {
        $this->actAs();

        $this->get('/dashboard?period=7d&granularity=month')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters', ['period' => '7d', 'granularity' => 'day'])->has('teamGrowth.points', 7));
        $this->get('/dashboard?period=12m')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.granularity', 'month')->where('teamGrowth.points', fn ($points) => count($points) >= 12 && count($points) <= 13));
        $this->get('/dashboard?period=90d&granularity=week')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.granularity', 'week')->where('teamGrowth.points', fn ($points) => count($points) >= 13 && count($points) <= 14));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actAs();

        $this->get('/dashboard?period=forever')->assertSessionHasErrors('period');
        $this->get('/dashboard?granularity=hour')->assertSessionHasErrors('granularity');
    }

    public function test_team_growth_counts_only_this_workspaces_members_inside_the_period(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $other = $this->otherWorkspace();
        $this->join($workspace, now()->subDays(2)->toDateTimeString());
        $this->join($workspace, now()->subDays(2)->toDateTimeString());
        $this->join($workspace, now()->subDays(40)->toDateTimeString());
        $this->join($other, now()->subDays(2)->toDateTimeString());

        $this->get('/dashboard?period=30d')->assertInertia(fn (AssertableInertia $page) => $page
            // The two recent joins, the actor's own membership (today) and none of the 40-day-old or foreign ones.
            ->where('teamGrowth.total', 3)
            ->where('teamGrowth.points', fn ($points) => collect($points)->sum('value') === 3 && collect($points)->contains('value', 2)));
        $this->get('/dashboard?period=7d')->assertInertia(fn (AssertableInertia $page) => $page->where('teamGrowth.total', 3));
        $this->get('/dashboard?period=90d')->assertInertia(fn (AssertableInertia $page) => $page->where('teamGrowth.total', 4));
    }

    public function test_the_workspace_always_comes_from_the_session(): void
    {
        $this->actAs();
        $other = $this->otherWorkspace();
        $this->join($other, now()->toDateTimeString());

        // A `workspace_id` is not an input at all; a `workspace` scope is refused for anyone who may not choose one.
        $this->get("/dashboard?workspace_id={$other->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('teamGrowth.total', 1)->where('stats.users', 1)->where('scope.mode', 'active')->where('scope.canChoose', false));
        $this->get("/dashboard?workspace={$other->id}")->assertForbidden();
        $this->get('/dashboard?workspace=all')->assertForbidden();
    }

    public function test_a_superuser_outside_the_administrative_workspace_is_scoped_like_anyone_else(): void
    {
        $this->actAs('admin', superuser: true);
        $other = $this->otherWorkspace();

        $this->get("/dashboard?workspace={$other->id}")->assertForbidden();
        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->where('scope.canChoose', false));
    }

    public function test_an_administrator_in_the_administrative_workspace_chooses_all_or_one(): void
    {
        $this->actAs('admin', superuser: true);
        config(['workspace.admin_code' => 'TEST_WS']);
        $workspace = Workspace::first();
        $other = $this->otherWorkspace();
        $this->join($workspace, now()->subDay()->toDateTimeString());
        $this->join($other, now()->toDateTimeString());
        $this->join($other, now()->toDateTimeString());
        $shared = $this->join($other, now()->toDateTimeString());
        $workspace->users()->attach($shared->id, ['role' => 'cliente']);

        // Default: the Workspace of the session (the actor, one more person and the shared one).
        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scope.canChoose', true)->where('scope.mode', 'active')->where('stats.users', 3));
        // A specific Workspace: only its people.
        $this->get("/dashboard?workspace={$other->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scope.mode', 'workspace')->where('scope.workspace.code', 'OTHER_WS')->where('stats.users', 3)->where('teamGrowth.total', 3)
            ->where('recentUsers', fn ($users) => collect($users)->every(fn ($u) => $u['workspace'] === null)));
        $this->get("/dashboard?workspace={$workspace->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('scope.mode', 'active')->where('stats.users', 3));
        // All: every person once, and each recent person says where they are.
        $this->get('/dashboard?workspace=all')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scope.mode', 'all')->where('scope.workspace', null)->where('stats.users', 5)
            ->where('recentUsers', fn ($users) => collect($users)->every(fn ($u) => $u['workspace'] !== null)));
        $this->get('/dashboard?workspace=999999')->assertNotFound();
        $this->get('/dashboard?workspace=abc')->assertSessionHasErrors('workspace');
    }

    public function test_the_period_follows_the_workspace_timezone(): void
    {
        $period = ReportPeriod::make('7d', null, 'America/Bogota');

        $this->assertSame('America/Bogota', $period->from->timezoneName);
        $this->assertTrue($period->from->isStartOfDay());
        $this->assertTrue($period->to->isSameDay(CarbonImmutable::now('America/Bogota')));
        $this->assertCount(7, $period->buckets());
    }
}

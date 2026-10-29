<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Resolving the modules of the Workspace a request runs in is remembered on that request object only: never across requests, users
 * or Workspaces, and a change of plan is visible to the very next request.
 */
class ModuleAccessRequestMemoTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $one;

    private Workspace $two;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $organization = Organization::create(['name' => 'CCG']);
        $this->one = Workspace::create(['organization_id' => $organization->id, 'code' => 'CLIENTE_UNO', 'name' => 'Uno']);
        $this->two = Workspace::create(['organization_id' => $organization->id, 'code' => 'CLIENTE_DOS', 'name' => 'Dos']);
    }

    private function plan(Workspace $workspace, array $keys): Plan
    {
        $plan = Plan::create(['name' => 'Plan '.$workspace->code.Plan::count(), 'is_active' => true]);
        $plan->modules()->sync(Module::whereIn('key', $keys)->pluck('id'));
        $workspace->update(['plan_id' => $plan->id]);

        return $plan;
    }

    private function requestIn(?Workspace $workspace): Request
    {
        $request = Request::create('/dashboard');
        $request->attributes->set('workspace', $workspace);

        return $request;
    }

    private function queries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    public function test_the_same_request_resolves_its_workspace_modules_only_once(): void
    {
        $this->plan($this->one, ['conversations', 'assistants']);
        $access = app(ModuleAccess::class);
        $request = $this->requestIn($this->one);

        $first = $this->queries(fn () => $access->forWorkspaceInRequest($request, $this->one));
        $again = $this->queries(fn () => $access->forWorkspaceInRequest($request, $this->one));

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $again);
        $this->assertEqualsCanonicalizing(['conversations', 'assistants'], $access->forWorkspaceInRequest($request, $this->one));
    }

    public function test_another_workspace_never_gets_the_memo_of_the_request_workspace(): void
    {
        $this->plan($this->one, ['conversations', 'assistants']);
        $this->plan($this->two, ['conversations']);
        $access = app(ModuleAccess::class);
        $request = $this->requestIn($this->one);

        $access->forWorkspaceInRequest($request, $this->one); // fills the memo of Workspace one

        $this->assertSame(['conversations'], $access->forWorkspaceInRequest($request, $this->two));
        $this->assertContains('assistants', $access->forWorkspaceInRequest($request, $this->one));
    }

    public function test_a_request_without_workspace_gets_a_fresh_lookup_not_a_memo(): void
    {
        $this->plan($this->one, ['conversations']);

        $this->assertSame(['conversations'], app(ModuleAccess::class)->forWorkspaceInRequest($this->requestIn(null), $this->one));
    }

    public function test_the_memo_is_not_kept_between_requests_so_a_plan_change_is_seen_at_once(): void
    {
        $access = app(ModuleAccess::class);
        $this->plan($this->one, ['conversations']);

        $this->assertSame(['conversations'], $access->forWorkspaceInRequest($this->requestIn($this->one), $this->one));

        $this->plan($this->one, ['conversations', 'assistants']);

        $this->assertEqualsCanonicalizing(['conversations', 'assistants'], $access->forWorkspaceInRequest($this->requestIn($this->one->fresh()), $this->one->fresh()));
    }

    public function test_a_workspace_without_subscription_is_resolved_with_a_single_subscription_lookup(): void
    {
        $this->plan($this->one, ['conversations']);
        $reads = 0;
        DB::listen(function ($query) use (&$reads) {
            $reads += str_contains($query->sql, 'from "subscriptions"') ? 1 : 0;
        });

        app(ModuleAccess::class)->keys($this->one);

        $this->assertSame(1, $reads);
    }
}

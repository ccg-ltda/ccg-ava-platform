<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceModuleOverride;
use App\Services\ModuleAccess;
use App\Services\ModuleCatalog;
use App\Services\SubscriptionService;
use Database\Seeders\ModuleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Plan -> subscription -> Workspace modules: automatic application of a plan on a CONFIRMED payment (idempotent), the authorized exceptions of one
 * Workspace over its plan, the empty state, and the isolation between Workspaces. There is no payment provider: payment confirmation is simulated here
 * only by calling the integration point the provider will use, never by the application itself.
 */
class WorkspaceModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $dev;

    private Workspace $a;

    private Workspace $b;

    private Plan $health;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->seed(ModuleCatalogSeeder::class);
        $organization = Organization::create(['name' => 'CCG']);
        $this->dev = Workspace::create(['organization_id' => $organization->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Dev']);
        $this->a = Workspace::create(['organization_id' => $organization->id, 'code' => 'CLIENTE_A', 'name' => 'A']);
        $this->b = Workspace::create(['organization_id' => $organization->id, 'code' => 'CLIENTE_B', 'name' => 'B']);

        // The offer: Salud = Agenda (with what it needs), Reportes and Dashboard. Configured as data, no code per client.
        $this->health = Plan::create(['name' => 'Salud']);
        $this->health->modules()->sync($this->ids(['dashboard', 'reports', 'service_catalog', 'professionals', 'bookings']));
    }

    /** @param  list<string>  $keys @return list<int> */
    private function ids(array $keys): array
    {
        return Module::whereIn('key', $keys)->pluck('id')->all();
    }

    private function id(string $key): int
    {
        return (int) Module::where('key', $key)->value('id');
    }

    private function actIn(Workspace $workspace, string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, ['role' => $role]);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    private function keys(Workspace $workspace): array
    {
        return app(ModuleAccess::class)->keys($workspace->fresh());
    }

    private function service(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }

    // --- the offer is applied by the subscription, never by hand -----------------------------------------------------

    public function test_a_confirmed_payment_activates_the_subscription_and_applies_the_plan_without_manual_steps(): void
    {
        $before = $this->keys($this->a); // default plan
        $subscription = $this->service()->register($this->a, $this->health);

        $this->assertSame(Subscription::PENDING, $subscription->status);
        $this->assertSame($before, $this->keys($this->a), 'registering gives and takes nothing');

        $this->service()->confirmPayment($subscription, 'PAY-001');

        $this->assertSame($this->health->id, $this->a->fresh()->plan_id);
        $this->assertSame(Subscription::ACTIVE, $subscription->fresh()->status);
        $this->assertEqualsCanonicalizing(['dashboard', 'reports', 'service_catalog', 'professionals', 'bookings'], $this->keys($this->a));
    }

    public function test_an_unconfirmed_payment_gives_no_access_and_cannot_be_activated(): void
    {
        $before = $this->keys($this->a);
        $subscription = $this->service()->register($this->a, $this->health);

        try {
            $this->service()->activate($subscription);
            $this->fail('activation without a confirmed payment must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_status', $e->errors());
        }

        $this->assertSame(Subscription::PENDING, $subscription->fresh()->status);

        // The chosen plan is NOT applied: nothing paid, nothing granted (and what the Workspace already had is not taken away by a request).
        $this->assertNotSame($this->health->id, $this->a->fresh()->plan_id);
        $this->assertSame($before, $this->keys($this->a));
        $this->assertNotContains('bookings', $this->keys($this->a));

        $this->service()->failPayment($subscription);
        $this->assertSame(Subscription::PAYMENT_FAILED, $subscription->fresh()->payment_status);
        $this->assertSame($before, $this->keys($this->a));
        $this->assertNotContains('bookings', $this->keys($this->a));
    }

    public function test_repeating_the_activation_changes_nothing_and_keeps_the_workspace_exceptions(): void
    {
        $subscription = $this->service()->register($this->a, $this->health);
        $this->service()->confirmPayment($subscription, 'PAY-001');
        $activatedAt = $subscription->fresh()->activated_at;

        app(ModuleCatalog::class)->setWorkspaceOverrides($this->a->fresh(), [], [$this->id('reports')], 'Sin reportes', null);
        $rows = WorkspaceModuleOverride::count();
        $audits = AuditLog::count();

        // The provider notifies twice, and a retry activates again.
        $this->service()->confirmPayment($subscription->fresh(), 'PAY-001');
        $this->service()->confirmPayment($subscription->fresh(), 'PAY-001');
        $this->service()->activate($subscription->fresh());

        $this->assertSame(1, Subscription::where('workspace_id', $this->a->id)->count());
        $this->assertSame($rows, WorkspaceModuleOverride::count());
        $this->assertSame($audits, AuditLog::count(), 'no new events for a repeated notification');
        $this->assertEquals($activatedAt, $subscription->fresh()->activated_at);
        $this->assertNotContains('reports', $this->keys($this->a), 'the exception survives');
        $this->assertSame('revoke', WorkspaceModuleOverride::where('workspace_id', $this->a->id)->value('mode'));
    }

    public function test_a_confirmed_payment_is_kept_when_the_plan_cannot_be_applied_and_the_retry_completes_it(): void
    {
        $incomplete = Plan::create(['name' => 'Incompleto']);
        $incomplete->modules()->sync($this->ids(['campaigns'])); // needs customers
        $subscription = $this->service()->register($this->a, $incomplete);

        try {
            $this->service()->confirmPayment($subscription, 'PAY-009');
            $this->fail('an incomplete plan is not applied');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('plan_id', $e->errors());
        }

        $this->assertSame(Subscription::PAYMENT_CONFIRMED, $subscription->fresh()->payment_status, 'the payment is not lost');
        $this->assertSame(Subscription::PENDING, $subscription->fresh()->status);

        $incomplete->modules()->sync($this->ids(['customers', 'campaigns']));
        $this->service()->confirmPayment($subscription->fresh(), 'PAY-009');
        $this->assertSame(Subscription::ACTIVE, $subscription->fresh()->status);
        $this->assertEqualsCanonicalizing(['customers', 'campaigns'], $this->keys($this->a));
    }

    // --- states of the subscription ---------------------------------------------------------------------------------

    public function test_subscription_states_decide_access_and_never_delete_data(): void
    {
        $subscription = $this->service()->register($this->a, $this->health);
        $this->service()->confirmPayment($subscription, 'PAY-001');
        $this->a->bookingSetting()->create(['require_confirmation' => false]);

        $this->service()->transition($subscription->fresh(), Subscription::GRACE);
        $this->assertContains('bookings', $this->keys($this->a), 'grace keeps the access');

        $this->service()->transition($subscription->fresh(), Subscription::SUSPENDED);
        $this->assertSame([], $this->keys($this->a));
        $this->assertNotNull($this->a->bookingSetting()->first(), 'suspending deletes nothing');

        // A renewal payment (another reference) reactivates it.
        $this->service()->confirmPayment($subscription->fresh(), 'PAY-002');
        $this->assertContains('bookings', $this->keys($this->a));

        $this->service()->transition($subscription->fresh(), Subscription::EXPIRED);
        $this->assertSame([], $this->keys($this->a));
        $this->service()->transition($subscription->fresh(), Subscription::CANCELLED);
        $this->assertSame([], $this->keys($this->a));

        // Registering again after it ended never reopens the modules without a confirmed payment.
        $again = $this->service()->register($this->a, $this->health);
        $this->assertSame(Subscription::PENDING, $again->status);
        $this->assertSame([], $this->keys($this->a));
        $this->service()->confirmPayment($again, 'PAY-003');
        $this->assertContains('bookings', $this->keys($this->a));
        $this->service()->transition($again->fresh(), Subscription::CANCELLED);

        $this->expectException(ValidationException::class); // cancelled is final
        $this->service()->transition($subscription->fresh(), Subscription::ACTIVE);
    }

    public function test_a_workspace_without_a_subscription_keeps_working_by_its_plan(): void
    {
        $this->a->update(['plan_id' => $this->health->id]);

        $this->assertNull(Subscription::where('workspace_id', $this->a->id)->first());
        $this->assertContains('bookings', $this->keys($this->a));
    }

    public function test_a_suspended_subscription_blocks_the_backend_with_a_clear_message(): void
    {
        $subscription = $this->service()->register($this->a, $this->health);
        $this->service()->confirmPayment($subscription, 'PAY-001');
        $this->service()->transition($subscription->fresh(), Subscription::SUSPENDED);

        $this->actIn($this->a);
        $this->get('/reports')->assertForbidden();
        $this->get('/dashboard')->assertRedirect(route('workspace.empty'));
        $this->get('/sin-modulos')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Workspace/NoModules')->where('reason', 'subscription'));
    }

    // --- plans with any number of modules -----------------------------------------------------------------------------

    public function test_a_plan_can_have_zero_modules_and_the_workspace_sees_the_empty_state(): void
    {
        $this->actIn($this->dev, 'plataforma');
        $this->post('/plans', ['name' => 'Vacío', 'module_ids' => []])->assertSessionHasNoErrors();
        $empty = Plan::where('name', 'Vacío')->firstOrFail();
        $this->assertSame(0, $empty->modules()->count());

        $this->a->update(['plan_id' => $empty->id]);
        $this->actIn($this->a);

        $this->get('/dashboard')->assertRedirect(route('workspace.empty'));
        $this->get('/sin-modulos')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Workspace/NoModules')->where('reason', 'empty'));
        $this->get('/customers')->assertForbidden();
        $this->get('/users')->assertForbidden();
        $this->get('/profile')->assertOk(); // the account itself stays reachable
    }

    public function test_the_empty_page_sends_a_workspace_without_dashboard_to_its_first_module(): void
    {
        $plan = Plan::create(['name' => 'Solo clientes']);
        $plan->modules()->sync($this->ids(['customers']));
        $this->a->update(['plan_id' => $plan->id]);
        $this->actIn($this->a);

        $this->get('/dashboard')->assertRedirect(route('workspace.empty'));
        $this->get('/sin-modulos')->assertRedirect(route('customers.index'));
    }

    // --- exceptions of one Workspace ------------------------------------------------------------------------------

    public function test_an_exception_affects_only_its_workspace_and_never_the_plan(): void
    {
        foreach ([$this->a, $this->b] as $workspace) {
            $this->service()->confirmPayment($this->service()->register($workspace, $this->health), 'PAY-'.$workspace->code);
        }

        $this->actIn($this->dev, 'plataforma');
        $this->put("/workspaces/{$this->b->id}/modules", ['granted' => [$this->id('customers')], 'revoked' => [$this->id('reports')], 'reason' => 'Pidió quitar reportes'])
            ->assertSessionHasNoErrors();

        $this->assertContains('reports', $this->keys($this->a));
        $this->assertNotContains('reports', $this->keys($this->b));
        $this->assertContains('customers', $this->keys($this->b));
        $this->assertNotContains('customers', $this->keys($this->a));
        $this->assertEqualsCanonicalizing($this->ids(['dashboard', 'reports', 'service_catalog', 'professionals', 'bookings']), $this->health->modules()->pluck('modules.id')->all(), 'the plan is untouched');
        $this->assertSame(0, WorkspaceModuleOverride::where('workspace_id', $this->a->id)->count());
    }

    public function test_exceptions_do_not_change_when_the_offer_changes_and_stale_ones_are_harmless(): void
    {
        $this->service()->confirmPayment($this->service()->register($this->a, $this->health), 'PAY-1');
        app(ModuleCatalog::class)->setWorkspaceOverrides($this->a->fresh(), [], [$this->id('reports')], null, null);

        // CCG adds Customers to the offer and A is not silently reset: it keeps its exception and gets the new module.
        $this->health->modules()->attach($this->id('customers'));
        $this->assertContains('customers', $this->keys($this->a));
        $this->assertNotContains('reports', $this->keys($this->a));

        // The plan drops Reportes: the revoke on it is simply moot.
        $this->health->modules()->detach($this->id('reports'));
        $this->assertNotContains('reports', $this->keys($this->a));
        $this->assertSame(1, WorkspaceModuleOverride::where('workspace_id', $this->a->id)->count());
    }

    public function test_a_granted_module_still_needs_its_dependencies(): void
    {
        $this->service()->confirmPayment($this->service()->register($this->a, $this->health), 'PAY-1');
        app(ModuleCatalog::class)->setWorkspaceOverrides($this->a->fresh(), [$this->id('campaigns')], [], null, null); // needs customers

        $this->assertNotContains('campaigns', $this->keys($this->a));

        app(ModuleCatalog::class)->setWorkspaceOverrides($this->a->fresh(), [$this->id('campaigns'), $this->id('customers')], [], null, null);
        $this->assertContains('campaigns', $this->keys($this->a));
    }

    public function test_a_hidden_module_stays_blocked_in_the_backend_even_for_an_administrator(): void
    {
        $this->service()->confirmPayment($this->service()->register($this->b, $this->health), 'PAY-1');
        app(ModuleCatalog::class)->setWorkspaceOverrides($this->b->fresh(), [], [$this->id('reports')], null, null);

        $this->actIn($this->b, 'admin');
        $this->get('/reports')->assertForbidden();
        $this->get('/dashboard')->assertOk();
        $this->get('/customers')->assertForbidden(); // never in the plan
    }

    public function test_only_the_platform_administration_can_see_or_change_the_modules_of_a_workspace(): void
    {
        $url = "/workspaces/{$this->b->id}/modules";
        $payload = ['granted' => [$this->id('customers')], 'revoked' => []];

        // A client administrator, even with every client permission, can neither read nor change it (nor its own).
        $this->actIn($this->b, 'admin');
        $this->get($url)->assertForbidden();
        $this->put($url, $payload)->assertForbidden();
        $this->put("/workspaces/{$this->a->id}/modules", $payload)->assertForbidden();

        // A platform role without the assignment permission can read but not change.
        $this->actIn($this->dev, 'supervisor');
        $this->get($url)->assertForbidden();

        $this->assertSame(0, WorkspaceModuleOverride::count());

        $this->actIn($this->dev, 'plataforma');
        $this->get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Plans/WorkspaceModules')->where('workspace.id', $this->b->id));
        $this->put($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, WorkspaceModuleOverride::where('workspace_id', $this->b->id)->count());

        // The administrative Workspace takes no exceptions.
        $this->put("/workspaces/{$this->dev->id}/modules", $payload)->assertSessionHasErrors('workspace_id');
        $this->get("/workspaces/{$this->dev->id}/modules")->assertNotFound();
    }

    public function test_exceptions_and_subscription_changes_are_audited_with_who_what_and_where(): void
    {
        $this->service()->confirmPayment($this->service()->register($this->b, $this->health), 'PAY-1');
        $user = $this->actIn($this->dev, 'plataforma');

        $this->put("/workspaces/{$this->b->id}/modules", ['granted' => [], 'revoked' => [$this->id('reports')], 'reason' => 'Contrato parcial'])->assertSessionHasNoErrors();

        $log = AuditLog::where('resource_type', 'workspace_modules')->firstOrFail();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($this->b->id, $log->workspace_id);
        $this->assertSame('CLIENTE_B', $log->workspace_code);
        $this->assertSame('Excepciones de módulos', $log->changes[0]['field']);
        $this->assertStringContainsString('Reportes', $log->changes[0]['after']);
        $this->assertSame('Contrato parcial', $log->changes[1]['after']);
        $this->assertNotNull($log->created_at);

        // The automatic activation is recorded as the system's own event for that Workspace, not attributed to a person.
        $activation = AuditLog::where('resource_type', 'subscription')->where('workspace_id', $this->b->id)->get();
        $this->assertTrue($activation->contains(fn (AuditLog $event) => $event->changes[0]['after'] === Subscription::ACTIVE));

        // The same exception saved again changes nothing and records nothing.
        $count = AuditLog::count();
        $this->put("/workspaces/{$this->b->id}/modules", ['granted' => [], 'revoked' => [$this->id('reports')]])->assertSessionHasNoErrors();
        $this->assertSame($count, AuditLog::count());
    }

    public function test_workspaces_never_share_records_because_they_share_a_plan(): void
    {
        foreach ([$this->a, $this->b] as $workspace) {
            $this->service()->confirmPayment($this->service()->register($workspace, $this->health), 'PAY-'.$workspace->code);
        }
        $this->a->customers()->create(['first_name' => 'Cliente de A', 'phone' => '+573001112233']);

        $this->actIn($this->b, 'admin');
        $this->get('/customers')->assertForbidden(); // not in the plan
        $this->assertSame(0, $this->b->customers()->count());
        $this->assertSame(1, $this->a->customers()->count());

        // Even with the module granted to B, it only ever sees its own rows.
        app(ModuleCatalog::class)->setWorkspaceOverrides($this->b->fresh(), [$this->id('customers')], [], null, null);
        $page = $this->get('/customers')->assertOk()->viewData('page')['props'];
        $this->assertStringNotContainsString('Cliente de A', json_encode($page));
    }

    public function test_the_catalog_view_of_a_workspace_tells_what_comes_from_the_plan_and_what_is_an_exception(): void
    {
        $this->service()->confirmPayment($this->service()->register($this->b, $this->health), 'PAY-1');
        app(ModuleCatalog::class)->setWorkspaceOverrides($this->b->fresh(), [$this->id('customers')], [$this->id('reports')], null, null);

        $modules = collect(app(ModuleCatalog::class)->workspaceModules($this->b->fresh())['modules'])->keyBy('key');

        $this->assertTrue($modules['dashboard']['inPlan']);
        $this->assertNull($modules['dashboard']['override']);
        $this->assertTrue($modules['dashboard']['effective']);
        $this->assertSame('revoke', $modules['reports']['override']);
        $this->assertFalse($modules['reports']['effective']);
        $this->assertSame('grant', $modules['customers']['override']);
        $this->assertTrue($modules['customers']['effective']);
        $this->assertFalse($modules['customers']['inPlan']);
    }
}

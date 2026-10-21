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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The internal activation flow end to end: a new customer's Workspace is closed until the first CONFIRMED payment; a valid confirmation opens it and applies
 * the plan once, whatever the number of notifications, without touching the exceptions of the Workspace or any other Workspace. No payment provider exists:
 * the tests call the integration point (`confirmPayment`) the provider's verified notification will call.
 */
class SubscriptionActivationFlowTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $a;

    private Workspace $b;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->seed(ModuleCatalogSeeder::class);
        $organization = Organization::create(['name' => 'CCG']);
        $this->a = Workspace::create(['organization_id' => $organization->id, 'code' => 'NUEVO_A', 'name' => 'Nuevo A']);
        $this->b = Workspace::create(['organization_id' => $organization->id, 'code' => 'NUEVO_B', 'name' => 'Nuevo B']);

        $this->plan = Plan::create(['name' => 'Salud']);
        $this->plan->modules()->sync($this->ids(['dashboard', 'reports', 'service_catalog', 'professionals', 'bookings']));
    }

    /** @param  list<string>  $keys @return list<int> */
    private function ids(array $keys): array
    {
        return Module::whereIn('key', $keys)->pluck('id')->all();
    }

    private function service(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }

    private function member(Workspace $workspace, string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function enter(User $user, Workspace $workspace): void
    {
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);
    }

    private function keys(Workspace $workspace): array
    {
        return app(ModuleAccess::class)->keys($workspace->fresh());
    }

    // --- 1. a new customer with a pending subscription ------------------------------------------------------------

    public function test_a_new_customer_with_a_pending_subscription_is_closed_and_the_default_plan_is_not_a_free_door(): void
    {
        $defaultPlan = $this->a->fresh()->plan_id;
        $user = $this->member($this->a);

        $subscription = $this->service()->registerNewCustomer($this->a, $this->plan);

        $this->assertSame(Subscription::PENDING, $subscription->status);
        $this->assertSame(Subscription::PAYMENT_PENDING, $subscription->payment_status);
        $this->assertFalse($this->a->fresh()->is_active);
        $this->assertSame($defaultPlan, $this->a->fresh()->plan_id, 'the chosen plan is not applied before the payment');

        $this->enter($user, $this->a);
        $this->get('/dashboard')->assertRedirect(route('pre-login'));
        $this->get('/reports')->assertRedirect(route('login')); // the session was closed by the first request
    }

    // --- 2-3. valid confirmation: activation, workspace on, modules available --------------------------------------

    public function test_a_valid_confirmation_opens_the_workspace_and_its_contracted_modules_without_manual_steps(): void
    {
        $user = $this->member($this->a);
        $subscription = $this->service()->registerNewCustomer($this->a, $this->plan);

        $this->service()->confirmPayment($subscription, 'PAY-A1');

        $this->assertSame(Subscription::ACTIVE, $subscription->fresh()->status);
        $this->assertTrue($this->a->fresh()->is_active);
        $this->assertSame($this->plan->id, $this->a->fresh()->plan_id);
        $this->assertEqualsCanonicalizing(['dashboard', 'reports', 'service_catalog', 'professionals', 'bookings'], $this->keys($this->a));

        $this->enter($user, $this->a);
        $this->get('/dashboard')->assertOk();
        $this->get('/reports')->assertOk();
        $this->get('/bookings')->assertOk();
    }

    // --- 4-5. repeated notifications and exceptions ----------------------------------------------------------------

    public function test_repeated_confirmations_do_not_duplicate_anything_and_keep_the_workspace_exceptions(): void
    {
        $subscription = $this->service()->registerNewCustomer($this->a, $this->plan);
        // CCG already personalized the offer for this customer before the payment arrived.
        app(ModuleCatalog::class)->setWorkspaceOverrides($this->a->fresh(), [$this->ids(['customers'])[0]], [$this->ids(['reports'])[0]], 'Contrato parcial', null);

        $this->service()->confirmPayment($subscription, 'PAY-A1');
        $subscription = $subscription->fresh();
        $events = AuditLog::count();
        $activatedAt = $subscription->activated_at;

        foreach (range(1, 3) as $_) {
            $this->service()->confirmPayment($subscription->fresh(), 'PAY-A1');
        }
        $this->service()->activate($subscription->fresh());

        $this->assertSame(1, Subscription::where('workspace_id', $this->a->id)->count());
        $this->assertSame(2, WorkspaceModuleOverride::where('workspace_id', $this->a->id)->count());
        $this->assertSame($events, AuditLog::count(), 'a repeated notification records nothing');
        $this->assertEquals($activatedAt, $subscription->fresh()->activated_at);
        $this->assertTrue($this->a->fresh()->is_active);
        $this->assertContains('customers', $this->keys($this->a));
        $this->assertNotContains('reports', $this->keys($this->a));
    }

    // --- 6. failed or pending payment -----------------------------------------------------------------------------

    public function test_a_pending_or_failed_payment_is_never_read_as_a_paid_subscription(): void
    {
        $user = $this->member($this->a);
        $subscription = $this->service()->registerNewCustomer($this->a, $this->plan);

        $this->service()->failPayment($subscription);
        $this->assertSame(Subscription::PAYMENT_FAILED, $subscription->fresh()->payment_status);

        try {
            $this->service()->activate($subscription->fresh());
            $this->fail('a failed payment cannot activate');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_status', $e->errors());
        }

        $this->assertSame(Subscription::PENDING, $subscription->fresh()->status);
        $this->assertFalse($this->a->fresh()->is_active);
        $this->assertNotSame($this->plan->id, $this->a->fresh()->plan_id);
        $this->enter($user, $this->a);
        $this->get('/dashboard')->assertRedirect(route('pre-login'));

        // A blank reference is not a payment either.
        $this->expectException(ValidationException::class);
        $this->service()->confirmPayment($subscription->fresh(), '   ');
    }

    public function test_a_payment_does_not_reactivate_a_workspace_that_was_switched_off_for_another_reason(): void
    {
        $this->a->update(['is_active' => false]); // an administrator deactivated it: not done by registerNewCustomer
        $subscription = $this->service()->register($this->a->fresh(), $this->plan);

        $this->service()->confirmPayment($subscription, 'PAY-A1');

        $this->assertSame(Subscription::ACTIVE, $subscription->fresh()->status);
        $this->assertFalse($this->a->fresh()->is_active, 'only a Workspace closed by its own registration is opened by the first payment');
    }

    public function test_a_payment_for_a_plan_that_cannot_be_applied_is_kept_and_completes_on_the_retry(): void
    {
        $this->member($this->a);
        $broken = Plan::create(['name' => 'Roto']);
        $broken->modules()->sync($this->ids(['campaigns'])); // needs customers: cannot be assigned
        $subscription = $this->service()->registerNewCustomer($this->a, $broken);

        try {
            $this->service()->confirmPayment($subscription, 'PAY-A1');
            $this->fail('an incomplete plan is not applied');
        } catch (ValidationException) {
        }

        $this->assertSame(Subscription::PAYMENT_CONFIRMED, $subscription->fresh()->payment_status);
        $this->assertSame(Subscription::PENDING, $subscription->fresh()->status);
        $this->assertFalse($this->a->fresh()->is_active, 'nothing opens while the plan cannot be applied');

        $broken->modules()->sync($this->ids(['customers', 'campaigns']));
        $this->service()->confirmPayment($subscription->fresh(), 'PAY-A1');

        $this->assertSame(Subscription::ACTIVE, $subscription->fresh()->status);
        $this->assertTrue($this->a->fresh()->is_active);
    }

    // --- 7. suspension, reactivation and invalid transitions -------------------------------------------------------

    public function test_suspension_and_reactivation_follow_the_allowed_transitions_and_keep_the_data(): void
    {
        $user = $this->member($this->a);
        $subscription = $this->service()->registerNewCustomer($this->a, $this->plan);
        $this->service()->confirmPayment($subscription, 'PAY-A1');
        $this->a->bookingSetting()->create(['require_confirmation' => false]);

        $this->service()->transition($subscription->fresh(), Subscription::SUSPENDED);
        $this->assertSame([], $this->keys($this->a));
        $this->assertTrue($this->a->fresh()->is_active, 'suspending withdraws the modules, it does not switch the Workspace off');
        $this->assertNotNull($this->a->bookingSetting()->first(), 'no data is deleted');

        $this->enter($user, $this->a);
        $this->get('/reports')->assertForbidden();
        $this->get('/dashboard')->assertRedirect(route('workspace.empty'));

        $this->service()->confirmPayment($subscription->fresh(), 'PAY-A2'); // renewal payment
        $this->assertSame(Subscription::ACTIVE, $subscription->fresh()->status);
        $this->assertContains('bookings', $this->keys($this->a));
        $this->get('/reports')->assertOk();
    }

    public function test_invalid_transitions_are_refused_and_a_cancelled_subscription_is_final(): void
    {
        $subscription = $this->service()->registerNewCustomer($this->a, $this->plan);

        foreach ([Subscription::ACTIVE, Subscription::GRACE, Subscription::SUSPENDED, Subscription::EXPIRED] as $to) {
            try {
                $this->service()->transition($subscription->fresh(), $to); // a pending one cannot jump past the payment
                $this->fail("pending -> {$to} must be refused");
            } catch (ValidationException) {
            }
        }
        $this->assertSame(Subscription::PENDING, $subscription->fresh()->status);

        $this->service()->transition($subscription->fresh(), Subscription::CANCELLED);

        try {
            $this->service()->confirmPayment($subscription->fresh(), 'PAY-LATE');
            $this->fail('a cancelled subscription receives no payment');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertNull($subscription->fresh()->payment_reference);
        $this->assertFalse($this->a->fresh()->is_active);
    }

    public function test_a_payment_reference_cannot_be_used_by_two_subscriptions(): void
    {
        $first = $this->service()->registerNewCustomer($this->a, $this->plan);
        $second = $this->service()->registerNewCustomer($this->b, $this->plan);
        $this->service()->confirmPayment($first, 'PAY-SAME');

        try {
            $this->service()->confirmPayment($second, 'PAY-SAME');
            $this->fail('a reference belongs to one subscription');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_reference', $e->errors());
        }

        $this->assertSame(Subscription::PENDING, $second->fresh()->status);
        $this->assertFalse($this->b->fresh()->is_active);
    }

    // --- 8-9. isolation and direct routes -------------------------------------------------------------------------

    public function test_activating_one_workspace_changes_nothing_in_another_and_hidden_modules_stay_blocked(): void
    {
        $userA = $this->member($this->a);
        $userB = $this->member($this->b);
        $this->service()->registerNewCustomer($this->a, $this->plan);
        $subscriptionB = $this->service()->registerNewCustomer($this->b, $this->plan);
        $this->service()->confirmPayment(Subscription::where('workspace_id', $this->a->id)->first(), 'PAY-A1');

        // A is open, B (still pending) is not and keeps its own state.
        $this->assertTrue($this->a->fresh()->is_active);
        $this->assertFalse($this->b->fresh()->is_active);
        $this->assertSame(Subscription::PENDING, $subscriptionB->fresh()->status);
        $this->assertSame(0, WorkspaceModuleOverride::count());

        $this->enter($userB, $this->b);
        $this->get('/dashboard')->assertRedirect(route('pre-login'));

        $this->enter($userA, $this->a);
        $this->get('/dashboard')->assertOk();
        $this->get('/customers')->assertForbidden(); // contracted by nobody
        $this->get('/campaigns')->assertForbidden();
        $this->get('/users')->assertForbidden();
        $this->get('/integrations')->assertForbidden();

        // B pays later with its own reference: A is unaffected and B now has its own access.
        $this->service()->confirmPayment($subscriptionB->fresh(), 'PAY-B1');
        $this->assertTrue($this->b->fresh()->is_active);
        $this->assertSame(2, Subscription::count());
        $this->assertSame($this->plan->id, $this->a->fresh()->plan_id);
    }

    // --- 10. audit ------------------------------------------------------------------------------------------------

    public function test_the_relevant_transitions_are_audited_for_their_own_workspace(): void
    {
        $subscription = $this->service()->registerNewCustomer($this->a, $this->plan);
        $this->service()->confirmPayment($subscription, 'PAY-A1');
        $this->service()->transition($subscription->fresh(), Subscription::GRACE);
        $this->service()->transition($subscription->fresh(), Subscription::SUSPENDED);

        $events = AuditLog::where('resource_type', 'subscription')->where('workspace_id', $this->a->id)->orderBy('id')->get();
        $steps = $events->map(fn (AuditLog $event) => collect($event->changes)->firstWhere('field', 'Estado de la suscripción')['after'] ?? null)->filter()->values()->all();

        $this->assertSame([Subscription::PENDING, Subscription::ACTIVE, Subscription::GRACE, Subscription::SUSPENDED], $steps);
        $this->assertTrue($events->every(fn (AuditLog $event) => $event->actor === 'system' && $event->workspace_code === 'NUEVO_A'));
        $this->assertTrue($events->contains(fn (AuditLog $event) => collect($event->changes)->contains(fn ($change) => $change['field'] === 'Workspace' && $change['after'] === 'Activo')));
        $this->assertSame(0, AuditLog::where('workspace_id', $this->b->id)->count());
    }

    // --- authorization: nothing over HTTP changes a subscription -------------------------------------------------

    public function test_no_route_lets_anyone_change_a_subscription_from_the_browser_or_the_api(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri().' '.$route->getName())->implode("\n");

        $this->assertStringNotContainsStringIgnoringCase('subscription', $names);
        $this->assertStringNotContainsStringIgnoringCase('suscrip', $names);
        $this->assertStringNotContainsStringIgnoringCase('payment', $names);
    }

    public function test_the_migration_is_additive_reversible_and_leaves_existing_workspaces_working(): void
    {
        $this->a->update(['plan_id' => $this->plan->id]);
        $path = 'database/migrations/2026_10_24_100000_create_subscriptions_and_workspace_module_overrides.php';

        Artisan::call('migrate:rollback', ['--path' => $path, '--realpath' => false]);
        $this->assertFalse(Schema::hasTable('subscriptions'));
        $this->assertFalse(Schema::hasTable('workspace_module_overrides'));
        $this->assertTrue(Schema::hasTable('workspaces'), 'nothing else is touched');

        Artisan::call('migrate', ['--path' => $path, '--realpath' => false]);
        $this->assertTrue(Schema::hasColumns('subscriptions', ['workspace_id', 'plan_id', 'status', 'payment_status', 'payment_reference', 'activates_workspace']));

        // A Workspace that existed before has no subscription and keeps working by its plan.
        $this->assertNull(Subscription::where('workspace_id', $this->a->id)->first());
        $this->assertContains('reports', $this->keys($this->a));
    }
}

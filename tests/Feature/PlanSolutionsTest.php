<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use App\Services\ModuleCatalog;
use App\Services\SignupCatalog;
use App\Services\SubscriptionService;
use Database\Seeders\ModuleCatalogSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/** One plan offered under several commercial solutions: administration, public offer, contracts that never change, audit and the migration. */
class PlanSolutionsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    /** @var array<string, ModuleCategory> */
    private array $solutions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->seed(ModuleCatalogSeeder::class);

        foreach (['health', 'delivery', 'ecommerce', 'services_bookings'] as $key) {
            $this->solutions[$key] = tap(ModuleCategory::where('key', $key)->firstOrFail(), fn ($category) => $category->update(['is_public' => true]));
        }
    }

    private function asPlatform(): User
    {
        $dev = Workspace::create(['organization_id' => Organization::create(['name' => 'CCG'])->id, 'code' => config('workspace.admin_code'), 'name' => 'Dev']);
        $user = User::factory()->create();
        Role::findOrCreate('plataforma', 'web');
        $dev->users()->attach($user->id, ['role' => 'plataforma']);
        $this->actingAs($user)->withSession(['workspace_id' => $dev->id]);

        return $user;
    }

    /** @return list<int> */
    private function ids(string ...$keys): array
    {
        return array_values(array_map(fn (string $key) => $this->solutions[$key]->id, $keys));
    }

    /** @param  list<int>  $solutions */
    private function offer(string $name, array $solutions, array $modules = ['dashboard', 'reports']): array
    {
        return ['name' => $name, 'solution_ids' => $solutions, 'price_monthly' => 100000, 'currency' => 'COP', 'is_public' => true, 'module_ids' => Module::whereIn('key', $modules)->pluck('id')->all()];
    }

    public function test_one_plan_is_created_once_and_offered_under_several_solutions(): void
    {
        $this->asPlatform();
        $all = $this->ids('health', 'delivery', 'ecommerce', 'services_bookings');

        $this->post('/plans', $this->offer('Pro', $all))->assertSessionHasNoErrors();

        $pro = Plan::where('name', 'Pro')->firstOrFail();
        $this->assertSame(1, Plan::where('name', 'Pro')->count(), 'the plan is not duplicated per solution');
        $this->assertEqualsCanonicalizing($all, $pro->solutionIds());
        $this->assertSame(4, DB::table('module_category_plan')->where('plan_id', $pro->id)->count());

        $audited = AuditLog::where('resource_type', 'plan')->where('resource_id', $pro->id)->get()->contains(fn ($entry) => str_contains(json_encode($entry->changes), 'Soluciones'));
        $this->assertTrue($audited, 'the commercial change is audited');
    }

    public function test_a_plan_appears_exactly_once_under_each_of_its_solutions_in_the_public_offer(): void
    {
        $this->asPlatform();
        $this->post('/plans', $this->offer('Pro', $this->ids('health', 'delivery')))->assertSessionHasNoErrors();
        $this->post('/plans', $this->offer('Salud Oferta', $this->ids('health')))->assertSessionHasNoErrors();
        $catalog = app(SignupCatalog::class);
        $names = fn (string $key) => collect($catalog->plans($key))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Pro', 'Salud Oferta'], $names((string) $this->solutions['health']->id));
        $this->assertSame(['Pro'], $names((string) $this->solutions['delivery']->id));
        $this->assertSame([], $names((string) $this->solutions['ecommerce']->id));

        // Editing the relation many times never duplicates the plan under a solution.
        $pro = Plan::where('name', 'Pro')->firstOrFail();
        foreach ([$this->ids('health', 'delivery'), $this->ids('delivery', 'health'), $this->ids('health')] as $ids) {
            $this->put("/plans/{$pro->id}", $this->offer('Pro', $ids))->assertSessionHasNoErrors();
        }
        $this->put("/plans/{$pro->id}", $this->offer('Pro', $this->ids('health', 'delivery')))->assertSessionHasNoErrors();

        $this->assertSame(['Pro', 'Salud Oferta'], $names((string) $this->solutions['health']->id));
        $this->assertSame(2, DB::table('module_category_plan')->where('plan_id', $pro->id)->count());
        $this->assertSame(1, collect($catalog->plans((string) $this->solutions['delivery']->id))->where('name', 'Pro')->count());
    }

    public function test_the_database_and_the_server_refuse_the_same_solution_twice(): void
    {
        $this->asPlatform();
        $health = $this->solutions['health']->id;
        $this->post('/plans', $this->offer('Pro', [$health, $health]))->assertSessionHasErrors('solution_ids.1');

        $plan = Plan::create(['name' => 'X']);
        $plan->solutions()->attach($health);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('module_category_plan')->insert(['plan_id' => $plan->id, 'module_category_id' => $health]);
    }

    public function test_only_active_commercial_solutions_can_be_chosen(): void
    {
        $this->asPlatform();
        $shared = ModuleCategory::where('key', 'shared')->firstOrFail();
        $this->solutions['delivery']->update(['is_active' => false]);

        $this->post('/plans', $this->offer('A', [$shared->id]))->assertSessionHasErrors('solution_ids.0');
        $this->post('/plans', $this->offer('B', [$this->solutions['delivery']->id]))->assertSessionHasErrors('solution_ids.0');
        $this->post('/plans', $this->offer('C', [999999]))->assertSessionHasErrors('solution_ids.0');
        $this->assertSame(0, Plan::whereIn('name', ['A', 'B', 'C'])->count());
    }

    public function test_a_public_plan_sold_online_needs_at_least_one_solution_but_a_form_without_the_field_changes_nothing(): void
    {
        $this->asPlatform();
        $this->post('/plans', ['name' => 'Sin solucion', 'price_monthly' => 100, 'currency' => 'COP', 'is_public' => true])->assertSessionHasErrors('solution_ids');

        $this->post('/plans', $this->offer('Pro', $this->ids('health', 'delivery')))->assertSessionHasNoErrors();
        $pro = Plan::where('name', 'Pro')->firstOrFail();

        $this->put("/plans/{$pro->id}", ['name' => 'Pro 2'])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing($this->ids('health', 'delivery'), $pro->fresh()->solutionIds());
        $this->put("/plans/{$pro->id}", $this->offer('Pro 2', []))->assertSessionHasErrors('solution_ids');
    }

    public function test_only_the_platform_administration_manages_the_relation(): void
    {
        $client = $this->bookingWorkspace('CLIENTE', ['dashboard', 'settings']);
        $this->actAsMember($client);

        $this->post('/plans', $this->offer('Pro', $this->ids('health')))->assertForbidden();
        $this->assertSame(0, Plan::where('name', 'Pro')->count());

        $plan = Plan::create(['name' => 'Existente']);
        $this->put("/plans/{$plan->id}", $this->offer('Existente', $this->ids('health')))->assertForbidden();
        $this->assertSame([], $plan->fresh()->solutionIds());
    }

    public function test_removing_a_solution_from_a_plan_never_changes_a_contracted_workspace(): void
    {
        $this->asPlatform();
        $this->post('/plans', $this->offer('Pro', $this->ids('health', 'delivery'), ['dashboard', 'reports', 'customers']))->assertSessionHasNoErrors();
        $pro = Plan::where('name', 'Pro')->firstOrFail();

        $client = Workspace::create(['organization_id' => Organization::create(['name' => 'Cliente'])->id, 'code' => 'CLIENTE', 'name' => 'Cliente']);
        $subscriptions = app(SubscriptionService::class);
        $terms = $subscriptions->buildTerms($pro, 'signup', null, null, [], $this->solutions['health']->id);
        $subscription = $subscriptions->registerNewCustomer($client, $pro, $terms);
        $subscriptions->confirmPayment($subscription, 'ref-1');
        $keysBefore = app(ModuleAccess::class)->keys($client->fresh());
        $before = $subscription->fresh()->only(['plan_id', 'status', 'terms']);

        $this->assertSame($this->solutions['health']->id, $terms['solution']['id'], 'the solution the customer chose is frozen');
        $this->assertSame($this->solutions['health']->id, app(ModuleCatalog::class)->solutionOf($client->fresh()));

        // Pro stops being offered in Salud and becomes exclusive to Delivery.
        $this->put("/plans/{$pro->id}", $this->offer('Pro', $this->ids('delivery'), ['dashboard', 'reports', 'customers']))->assertSessionHasNoErrors();

        $this->assertSame($this->ids('delivery'), $pro->fresh()->solutionIds());
        $this->assertEqualsCanonicalizing($keysBefore, app(ModuleAccess::class)->keys($client->fresh()), 'no module is granted or taken from an existing Workspace');
        $this->assertSame($before, $subscription->fresh()->only(['plan_id', 'status', 'terms']), 'the contract is untouched');
        $this->assertSame($pro->id, $client->fresh()->plan_id);
        $this->assertSame([], collect(app(SignupCatalog::class)->plans((string) $this->solutions['health']->id))->pluck('name')->all(), 'it is no longer offered under Salud');
    }

    public function test_assigning_a_plan_with_several_solutions_never_guesses_the_workspace_solution(): void
    {
        $catalog = app(ModuleCatalog::class);
        $multi = Plan::create(['name' => 'Pro']);
        $multi->solutions()->sync($this->ids('health', 'delivery'));
        $single = Plan::create(['name' => 'Solo Delivery']);
        $single->solutions()->sync($this->ids('delivery'));

        $fresh = Workspace::create(['organization_id' => Organization::create(['name' => 'A'])->id, 'code' => 'A', 'name' => 'A']);
        $fresh->update(['plan_id' => $multi->id]);
        $this->assertNull($catalog->solutionOf($fresh->fresh()), 'two solutions and no contract choice: nothing is fixed');
        $catalog->assertSameSolution($fresh->fresh(), $single); // allowed: nothing identifies the solution

        $only = Workspace::create(['organization_id' => Organization::create(['name' => 'B'])->id, 'code' => 'B', 'name' => 'B']);
        $only->update(['plan_id' => $single->id]);
        $this->assertSame($this->solutions['delivery']->id, $catalog->solutionOf($only->fresh()));
        $catalog->assertSameSolution($only->fresh(), $multi); // Pro includes Delivery

        $health = Plan::create(['name' => 'Salud']);
        $health->solutions()->sync($this->ids('health'));
        $this->expectException(ValidationException::class);
        $catalog->assertSameSolution($only->fresh(), $health);
    }

    public function test_the_primary_column_follows_the_first_solution_so_a_rollback_can_read_the_plan(): void
    {
        $plan = Plan::create(['name' => 'Pro']);
        app(ModuleCatalog::class)->syncSolutions($plan, $this->ids('delivery', 'health'));
        $this->assertContains($plan->fresh()->module_category_id, $this->ids('delivery', 'health'));

        app(ModuleCatalog::class)->syncSolutions($plan, []);
        $this->assertNull($plan->fresh()->module_category_id);
        $this->assertSame([], $plan->fresh()->solutionIds());
    }

    public function test_the_migration_copies_each_plan_solution_once_and_down_restores_the_primary_one(): void
    {
        $a = Plan::create(['name' => 'A']);
        $b = Plan::create(['name' => 'B']);
        $none = Plan::create(['name' => 'Sin']);
        DB::table('plans')->where('id', $a->id)->update(['module_category_id' => $this->solutions['health']->id]);
        DB::table('plans')->where('id', $b->id)->update(['module_category_id' => $this->solutions['health']->id]);

        $migration = require database_path('migrations/2026_11_04_100000_create_module_category_plan_table.php');
        $migration->down(); // the tests' schema already has the table: go back to the schema before it
        $this->assertFalse(Schema::hasTable('module_category_plan'));
        $this->assertSame($this->solutions['health']->id, DB::table('plans')->where('id', $a->id)->value('module_category_id'), 'the original column is never touched');

        $migration->up();

        $rows = DB::table('module_category_plan')->orderBy('plan_id')->get(['plan_id', 'module_category_id'])->map(fn ($row) => [$row->plan_id, $row->module_category_id])->all();
        $this->assertSame([[$a->id, $this->solutions['health']->id], [$b->id, $this->solutions['health']->id]], $rows, 'one row per plan with a solution, none for the custom plan');
        $this->assertSame(0, DB::table('module_category_plan')->where('plan_id', $none->id)->count());

        // Plans get more solutions later; a rollback writes the first one back where the column is empty and loses the rest (documented).
        DB::table('module_category_plan')->insert(['plan_id' => $b->id, 'module_category_id' => $this->solutions['delivery']->id]);
        DB::table('plans')->where('id', $b->id)->update(['module_category_id' => null]);
        $migration->down();

        $this->assertSame($this->solutions['health']->id, DB::table('plans')->where('id', $b->id)->value('module_category_id'));
        $this->assertSame($this->solutions['health']->id, DB::table('plans')->where('id', $a->id)->value('module_category_id'));
        $this->assertNull(DB::table('plans')->where('id', $none->id)->value('module_category_id'));

        $migration->up(); // leave the schema as the other tests expect it
    }
}

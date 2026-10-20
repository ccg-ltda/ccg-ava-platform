<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A database created BEFORE the permission split holds broad permissions (`manage-customers`, `manage-settings`, ...) assigned to roles. Running the
 * seeder on it must keep what each role could do (and not one thing more), keep every custom assignment, be harmless to run twice and never undo what a
 * superuser took away afterwards. The old database is rebuilt here from the catalog itself, so the test cannot drift from the real mapping.
 */
class LegacyRoleTransitionTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> the fine permissions that did not exist before the split */
    private function splitOff(): array
    {
        return collect(config('permissions.legacy'))->flatMap(fn (array $split) => $split['grants'])->unique()->values()->all();
    }

    /** @return list<string> the permissions an old database had */
    private function oldCatalog(): array
    {
        return collect(array_keys(config('permissions.permissions')))
            ->reject(fn (string $name) => in_array($name, $this->splitOff(), true))
            ->merge(array_keys(config('permissions.legacy')))
            ->unique()->values()->all();
    }

    /** @param  array<string, list<string>>  $roles role => permissions it held before the split */
    private function oldDatabase(array $roles): void
    {
        DB::table('role_has_permissions')->delete();
        DB::table('permissions')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->oldCatalog() as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        foreach ($roles as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }
    }

    private function seed_(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return list<string> */
    private function held(string $role): array
    {
        $names = Role::findByName($role, 'web')->permissions()->pluck('name')->all();
        sort($names);

        return $names;
    }

    /** What a role must hold after the transition: what it had (minus what was retired) plus the fine permissions of each broad one it had. */
    private function expected(array $before): array
    {
        $legacy = config('permissions.legacy');
        $after = collect($before)->reject(fn (string $name) => ($legacy[$name]['retire'] ?? false))->all();

        foreach ($before as $name) {
            $after = [...$after, ...($legacy[$name]['grants'] ?? [])];
        }

        $after = array_values(array_unique($after));
        sort($after);

        return $after;
    }

    public function test_every_broad_permission_is_replaced_by_exactly_its_fine_ones_and_nothing_else_changes(): void
    {
        $roles = ['view-dashboard' => ['view-dashboard']];

        foreach (array_keys(config('permissions.legacy')) as $broad) {
            $roles["con-{$broad}"] = ['view-dashboard', $broad];
        }

        $roles['mixto'] = ['view-dashboard', 'manage-customers', 'view-customers', 'manage-services', 'view-bookings', 'manage-users', 'view-chatbots'];
        $this->oldDatabase($roles);

        $this->seed_();

        foreach ($roles as $role => $before) {
            $this->assertSame($this->expected($before), $this->held($role), "role [{$role}] after the transition");
        }
    }

    public function test_a_role_without_a_broad_permission_receives_none_of_its_fine_ones(): void
    {
        $this->oldDatabase(['lector' => ['view-dashboard', 'view-customers', 'view-orders', 'view-chatbots']]);

        $this->seed_();

        $held = $this->held('lector');
        foreach (['create-customers', 'edit-customers', 'activate-customers', 'anonymize-customers', 'create-orders', 'edit-orders', 'cancel-orders', 'edit-settings', 'manage-integrations', 'create-chatbots'] as $write) {
            $this->assertNotContains($write, $held, "a read-only role must not gain [{$write}]");
        }
    }

    public function test_the_retired_broad_permissions_disappear_and_no_route_or_role_keeps_them(): void
    {
        $this->oldDatabase(['viejo' => ['view-dashboard', 'manage-settings', 'manage-orders', 'manage-customers']]);

        $this->seed_();

        foreach (collect(config('permissions.legacy'))->filter(fn (array $split) => $split['retire'])->keys() as $retired) {
            $this->assertDatabaseMissing('permissions', ['name' => $retired]);
            $this->assertNotContains($retired, $this->held('viejo'));
        }

        // The ones that are still the page permission of their resource stay.
        foreach (collect(config('permissions.legacy'))->reject(fn (array $split) => $split['retire'])->keys() as $kept) {
            $this->assertDatabaseHas('permissions', ['name' => $kept]);
        }
    }

    public function test_the_catalog_permission_never_granted_by_a_broad_one_is_not_handed_out(): void
    {
        $this->oldDatabase(['viejo' => ['view-dashboard', 'manage-settings', 'manage-users']]);

        $this->seed_();

        // `delete-integrations` was not part of manage-settings: only `admin` receives it.
        $this->assertNotContains('delete-integrations', $this->held('viejo'));
        $this->assertContains('delete-integrations', $this->held('admin'));
    }

    public function test_running_the_transition_twice_changes_nothing_and_keeps_what_a_superuser_removed_in_between(): void
    {
        $this->oldDatabase(['mixto' => ['view-dashboard', 'manage-customers', 'view-customers', 'manage-orders', 'view-orders']]);
        $this->seed_();
        $first = $this->held('mixto');
        $rows = DB::table('role_has_permissions')->count();
        $permissions = Permission::count();

        $this->seed_();
        $this->assertSame($first, $this->held('mixto'));
        $this->assertSame($rows, DB::table('role_has_permissions')->count());
        $this->assertSame($permissions, Permission::count());

        // A superuser takes one fine permission away; the seeder run again must not give it back.
        Role::findByName('mixto', 'web')->revokePermissionTo('anonymize-customers');
        $this->seed_();

        $this->assertNotContains('anonymize-customers', $this->held('mixto'));
        $this->assertContains('edit-customers', $this->held('mixto'));
    }

    public function test_a_custom_role_keeps_its_assignments_and_the_defaults_never_overwrite_an_existing_role(): void
    {
        $this->oldDatabase([
            'supervisor' => ['view-dashboard', 'view-users', 'view-chatbots', 'view-campaigns'],
            'agente' => ['view-dashboard', 'view-conversations'],
            'coordinador' => ['view-dashboard', 'view-users'],
        ]);

        $this->seed_();

        // Existing roles are not reset to the seeder defaults: the custom view-campaigns of `supervisor` stays, `agente` does not gain reply-conversations.
        $this->assertContains('view-campaigns', $this->held('supervisor'));
        $this->assertNotContains('reply-conversations', $this->held('agente'));
        $this->assertSame(['view-dashboard', 'view-users'], $this->held('coordinador'));
    }

    public function test_admin_holds_every_permission_except_the_commercial_and_private_data_ones(): void
    {
        $this->oldDatabase(['admin' => ['view-dashboard', 'manage-settings']]);

        $this->seed_();

        $expected = array_values(array_diff(array_keys(config('permissions.permissions')), RolesAndPermissionsSeeder::reserved()));
        sort($expected);
        $this->assertSame($expected, $this->held('admin'));
    }

    public function test_after_the_transition_a_member_of_an_old_role_is_authorized_by_the_new_permissions(): void
    {
        $this->oldDatabase(['gestor' => ['view-dashboard', 'view-customers', 'manage-customers']]);
        $this->seed_();

        $workspace = $this->actingAsWorkspaceMemberWithModules('gestor', ['dashboard', 'customers']);

        $this->get('/customers')->assertOk();
        $this->post('/customers', ['first_name' => 'Laura', 'last_name' => 'Pérez', 'phone' => '+57 311 222 3344', 'source' => 'manual', 'marketing_consent' => 'unknown'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $workspace->customers()->count());
    }

    private function actingAsWorkspaceMemberWithModules(string $role, array $modules)
    {
        $organization = Organization::create(['name' => 'Org']);
        $workspace = Workspace::create(['organization_id' => $organization->id, 'code' => 'VIEJO', 'name' => 'Viejo']);
        $workspace->settings()->create(array_merge(WorkspaceSetting::defaults(), ['timezone' => 'UTC']));
        $plan = Plan::create(['name' => 'Plan viejo']);
        $plan->modules()->sync(Module::whereIn('key', $modules)->pluck('id'));
        $workspace->update(['plan_id' => $plan->id]);
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, ['role' => $role]);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $workspace;
    }
}

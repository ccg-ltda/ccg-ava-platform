<?php

namespace Tests;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Runs before RefreshDatabase touches the schema: abort if the app points to a non-test database.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = config('database.default');

        TestDatabaseGuard::assertSafe($connection, config("database.connections.{$connection}.database"));
    }

    /**
     * The same role/permission catalog the seeders create:
     * admin = every permission, supervisor = view-dashboard + view-users + view-chatbots, cliente = view-dashboard,
     * agente = view-dashboard + view/reply conversations.
     */
    protected function seedRoleCatalog(): void
    {
        $permissions = collect(['manage-users', 'create-users', 'edit-users', 'assign-roles', 'deactivate-users', 'manage-settings', 'export-audit', 'view-dashboard', 'view-users', 'view-chatbots', 'manage-chatbots', 'view-conversations', 'reply-conversations', 'manage-conversations', 'view-bookings', 'manage-bookings', 'manage-services', 'view-customers', 'manage-customers', 'view-campaigns', 'manage-campaigns', 'view-catalog', 'manage-catalog', 'view-orders', 'manage-orders', 'process-orders', 'manage-plans', 'manage-modules', 'access-private-data'])
            ->each(fn (string $name) => Permission::findOrCreate($name, 'web'));

        // `admin` never holds the commercial permissions: only `plataforma` does (as in the seeder).
        Role::findOrCreate('admin', 'web')->syncPermissions($permissions->reject(fn (string $permission) => in_array($permission, ['manage-plans', 'manage-modules', 'access-private-data']))->all());
        Role::findOrCreate('plataforma', 'web')->syncPermissions(['view-dashboard', 'manage-plans', 'manage-modules']);
        Role::findOrCreate('soporte', 'web')->syncPermissions(['view-dashboard', 'view-conversations', 'access-private-data']);
        Role::findOrCreate('supervisor', 'web')->syncPermissions(['view-dashboard', 'view-users', 'view-chatbots']);
        Role::findOrCreate('cliente', 'web')->syncPermissions(['view-dashboard']);
        Role::findOrCreate('agente', 'web')->syncPermissions(['view-dashboard', 'view-conversations', 'reply-conversations']);
    }

    /**
     * Act as a user who is a member of a Workspace and has it selected in the session,
     * i.e. the state the app expects after a successful login.
     */
    protected function actingAsWorkspaceMember(User $user, string $role = 'cliente', bool $administrative = false): static
    {
        $this->seedRoleCatalog();
        Role::findOrCreate($role, 'web');

        $workspace = Workspace::orderBy('id')->first() ?? Workspace::create([
            'organization_id' => Organization::create(['name' => 'Test Org'])->id,
            'code' => 'TEST_WS',
            'name' => 'Test Workspace',
        ]);
        $workspace->users()->syncWithoutDetaching([$user->id => ['role' => $role]]);

        // A superuser carries platform privileges only inside the administrative Workspace: a test that needs them passes
        // `$administrative = true`, which makes this Workspace the administrative one; otherwise it is a plain client Workspace.
        if ($user->is_superuser && $administrative) {
            config(['workspace.admin_code' => $workspace->code]);
        }

        return $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);
    }
}

<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** A new installation gets the permission catalog and the `agente` role from the seeder, and an existing one keeps its data. */
class RolesSeederTest extends TestCase
{
    use RefreshDatabase;

    private function names(string $role): array
    {
        return Role::findByName($role, 'web')->permissions->pluck('name')->sort()->values()->all();
    }

    public function test_a_new_installation_gets_the_conversation_permissions_and_the_agent_role(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(
            ['manage-conversations', 'reply-conversations', 'view-conversations'],
            Permission::whereIn('name', ['view-conversations', 'reply-conversations', 'manage-conversations'])->pluck('name')->sort()->values()->all(),
        );
        $this->assertSame(['reply-conversations', 'view-conversations', 'view-dashboard'], $this->names('agente'));
        $this->assertSame(Permission::count(), count($this->names('admin')));
    }

    public function test_running_it_again_duplicates_nothing_and_keeps_manual_changes(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Role::findByName('supervisor', 'web')->givePermissionTo('view-conversations');
        Role::findByName('agente', 'web')->revokePermissionTo('view-dashboard');
        $roles = Role::count();
        $permissions = Permission::count();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame([$roles, $permissions], [Role::count(), Permission::count()]);
        $this->assertContains('view-conversations', $this->names('supervisor'));
        $this->assertNotContains('view-dashboard', $this->names('agente'));
    }

    public function test_an_existing_database_without_the_new_permissions_receives_them(): void
    {
        // The state of a database prepared before human attention existed: old catalog, no `agente` role.
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::whereIn('name', ['reply-conversations', 'manage-conversations'])->delete();
        Role::findByName('agente', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertContains('manage-conversations', $this->names('admin'));
        $this->assertSame(['reply-conversations', 'view-conversations', 'view-dashboard'], $this->names('agente'));
        $this->assertSame(['view-chatbots', 'view-dashboard', 'view-users'], $this->names('supervisor'));
    }
}

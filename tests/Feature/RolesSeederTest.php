<?php

namespace Tests\Feature;

use App\Services\PlatformAccess;
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

    /** @return list<string> */
    private function commercial(): array
    {
        return app(PlatformAccess::class)->commercial();
    }

    /** @return list<string> */
    private function sorted(array $names): array
    {
        sort($names);

        return array_values($names);
    }

    /** What an agent starts with: read, and each action of attending a conversation (the permissions are independent). */
    private function agent(): array
    {
        return $this->sorted(['view-dashboard', 'view-conversations', 'take-conversations', 'reply-conversations', 'release-conversations', 'resolve-conversations']);
    }

    public function test_a_new_installation_gets_the_conversation_permissions_and_the_agent_role(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(
            ['manage-conversations', 'release-conversations', 'reply-conversations', 'resolve-conversations', 'take-conversations', 'view-conversations'],
            Permission::whereIn('name', ['view-conversations', 'take-conversations', 'reply-conversations', 'release-conversations', 'resolve-conversations', 'manage-conversations'])->pluck('name')->sort()->values()->all(),
        );
        $this->assertSame($this->agent(), $this->names('agente'));
        $this->assertSame(Permission::count() - count(RolesAndPermissionsSeeder::reserved()), count($this->names('admin')), 'admin never receives the commercial or the private-data permissions');
        $this->assertSame($this->sorted(['view-dashboard', ...$this->commercial()]), $this->names('plataforma'));
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
        Permission::whereIn('name', ['take-conversations', 'reply-conversations', 'release-conversations', 'resolve-conversations', 'manage-conversations'])->delete();
        Role::findByName('agente', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertContains('manage-conversations', $this->names('admin'));
        $this->assertSame($this->agent(), $this->names('agente'));
        $this->assertSame(['view-chatbots', 'view-dashboard', 'view-users'], $this->names('supervisor'));
    }

    public function test_the_seeder_takes_the_commercial_permissions_back_from_admin_and_never_grants_them_to_others(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Role::findByName('admin', 'web')->givePermissionTo($this->commercial()); // what the previous version of the seeder did
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (['admin', 'supervisor', 'cliente', 'agente'] as $role) {
            $this->assertEmpty(array_intersect($this->commercial(), $this->names($role)), $role);
        }
        $this->assertSame($this->sorted(['view-dashboard', ...$this->commercial()]), $this->names('plataforma'));
    }
}

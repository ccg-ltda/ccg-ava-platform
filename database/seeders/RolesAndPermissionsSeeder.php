<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Permissions of the commercial administration: never given to `admin` or to anyone by this seeder. */
    public const PLATFORM_PERMISSIONS = ['manage-plans', 'manage-modules'];

    /** Access to private data of ANOTHER Workspace (support): separate from the commercial administration, never given to `admin` or to a superuser by this seeder. */
    public const PRIVATE_DATA_PERMISSIONS = ['access-private-data'];

    /**
     * Creates fine-grained permissions that split an existing broad one. When a permission is created now (first run after the
     * split), every role that already had the broad permission receives it, so no existing role loses an ability; on later runs
     * nothing is granted, so a superuser's choice to take one away is kept.
     *
     * @param  list<string>  $names
     */
    private function createPermissionsReplacing(string $broad, array $names): void
    {
        foreach ($names as $name) {
            $permission = Permission::firstOrCreate(['name' => $name]);

            if ($permission->wasRecentlyCreated) {
                Role::whereHas('permissions', fn ($query) => $query->where('name', $broad))->get()
                    ->each(fn (Role $role) => $role->givePermissionTo($permission));
            }
        }
    }

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Permissions that were split into finer ones: roles holding the broad one receive the fine ones the first time, then the
        // broad one is retired when no route uses it any more (config/permissions.php `legacy`). The permissions a database already
        // has are never recreated or renamed.
        foreach (config('permissions.legacy') as $broad => $split) {
            // On a fresh database the broad permission does not exist and nothing needs expanding; an existing one is only read.
            if (! Permission::where('name', $broad)->where('guard_name', 'web')->exists()) {
                continue;
            }

            $this->createPermissionsReplacing($broad, $split['grants']);
        }

        // Every permission of the catalog exists (config/permissions.php is the single list; nothing here repeats it).
        foreach (array_keys(config('permissions.permissions')) as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        // A retired broad permission leaves the catalog only after its holders received the fine ones above.
        foreach (config('permissions.legacy') as $broad => $split) {
            if ($split['retire'] && ! array_key_exists($broad, config('permissions.permissions'))) {
                Permission::where('name', $broad)->where('guard_name', 'web')->get()->each->delete();
            }
        }
        // Create Roles
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $supervisorRole = Role::firstOrCreate(['name' => 'supervisor']);
        $clienteRole = Role::firstOrCreate(['name' => 'cliente']);
        $agentRole = Role::firstOrCreate(['name' => 'agente']);
        // Starting point for who administers the commercial side: a role with the two permissions, assigned to nobody.
        $platformRole = Role::firstOrCreate(['name' => 'plataforma']);
        // Starting point for who may open a justified, temporary support access to another Workspace's private data: assigned to nobody.
        $supportRole = Role::firstOrCreate(['name' => 'soporte']);

        // `admin` always holds every permission (so a permission added later reaches it on the next run). The other
        // roles get their defaults only when they are created: an existing database keeps whatever a superuser
        // changed in the catalog, and the seeder can run again on it without undoing that.
        // The commercial permissions are the exception: no role receives them here except `plataforma`, and `admin` never
        // does, so a superuser decides explicitly who administers plans and the catalog (Usuarios y roles).
        // `admin` is a protected role (it cannot be edited), so only an earlier version of this seeder could have granted them: take them back.
        $adminRole->givePermissionTo(Permission::whereNotIn('name', [...self::PLATFORM_PERMISSIONS, ...self::PRIVATE_DATA_PERMISSIONS])->get());
        $adminRole->revokePermissionTo([...self::PLATFORM_PERMISSIONS, ...self::PRIVATE_DATA_PERMISSIONS]);

        foreach ([
            [$supervisorRole, ['view-dashboard', 'view-users', 'view-chatbots']],
            [$clienteRole, ['view-dashboard']],
            // Attends conversations (read, take, write, give back to the AI, resolve) but cannot assign them to others.
            [$agentRole, ['view-dashboard', 'view-conversations', 'reply-conversations']],
            [$platformRole, ['view-dashboard', ...self::PLATFORM_PERMISSIONS]],
            [$supportRole, ['view-dashboard', 'view-conversations', ...self::PRIVATE_DATA_PERMISSIONS]],
        ] as [$role, $defaults]) {
            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($defaults);
            }
        }

        // Sample accounts have well-known passwords: they exist only in local/testing, never elsewhere.
        // (The real administrator comes from AdminUserSeeder and its environment variables.)
        if (! app()->environment('local', 'testing')) {
            return;
        }

        // Create Default Administrator: Daniel
        User::firstOrCreate(
            ['email' => 'daniel@gmail.com'],
            [
                'name' => 'Administrador Daniel',
                'password' => Hash::make('123456789'),
            ],
        );

        // Create Test Supervisor
        User::firstOrCreate(
            ['email' => 'supervisor@ccg.com'],
            [
                'name' => 'Supervisor CCG',
                'password' => Hash::make('password123'),
            ],
        );

        // Create Test Cliente
        User::firstOrCreate(
            ['email' => 'cliente@ccg.com'],
            [
                'name' => 'Cliente CCG',
                'password' => Hash::make('password123'),
            ],
        );
    }
}

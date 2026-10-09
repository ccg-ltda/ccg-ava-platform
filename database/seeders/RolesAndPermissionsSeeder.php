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

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create Permissions
        Permission::firstOrCreate(['name' => 'manage-users']);
        Permission::firstOrCreate(['name' => 'manage-settings']);
        Permission::firstOrCreate(['name' => 'view-dashboard']);
        Permission::firstOrCreate(['name' => 'view-users']);
        Permission::firstOrCreate(['name' => 'view-chatbots']);
        Permission::firstOrCreate(['name' => 'manage-chatbots']);
        Permission::firstOrCreate(['name' => 'view-conversations']);
        Permission::firstOrCreate(['name' => 'reply-conversations']);
        Permission::firstOrCreate(['name' => 'manage-conversations']);

        // Create Roles
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $supervisorRole = Role::firstOrCreate(['name' => 'supervisor']);
        $clienteRole = Role::firstOrCreate(['name' => 'cliente']);
        $agentRole = Role::firstOrCreate(['name' => 'agente']);

        // `admin` always holds every permission (so a permission added later reaches it on the next run). The other
        // roles get their defaults only when they are created: an existing database keeps whatever a superuser
        // changed in the catalog, and the seeder can run again on it without undoing that.
        $adminRole->syncPermissions(Permission::all());

        foreach ([
            [$supervisorRole, ['view-dashboard', 'view-users', 'view-chatbots']],
            [$clienteRole, ['view-dashboard']],
            // Attends conversations (read, take, write, give back to the AI, resolve) but cannot assign them to others.
            [$agentRole, ['view-dashboard', 'view-conversations', 'reply-conversations']],
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

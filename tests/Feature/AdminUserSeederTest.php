<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.email' => 'admin@example.test', 'admin.password' => 'Sample-Pass-1*', 'admin.name' => 'Admin']);
    }

    public function test_it_creates_the_admin_with_role_permissions_verified_email_and_hashed_password(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@example.test')->firstOrFail();

        $this->assertTrue(Hash::check('Sample-Pass-1*', $admin->password));
        $this->assertNotSame('Sample-Pass-1*', $admin->password);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue($admin->is_superuser);
        $this->assertSame(
            Permission::count(),
            Role::findByName('admin', 'web')->permissions()->count(),
            'The admin role has every permission.'
        );
    }

    public function test_it_creates_the_role_when_it_does_not_exist(): void
    {
        $this->seed(AdminUserSeeder::class);

        $this->assertTrue(Role::where('name', 'admin')->exists());
    }

    public function test_running_it_twice_does_not_duplicate_anything_or_change_access(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(AdminUserSeeder::class);
        $id = User::where('email', 'admin@example.test')->value('id');
        $roles = Role::count();

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, User::where('email', 'admin@example.test')->count());
        $this->assertSame($id, User::where('email', 'admin@example.test')->value('id'));
        $this->assertSame($roles, Role::count());
        $admin = User::find($id);
        $this->assertTrue(Hash::check('Sample-Pass-1*', $admin->password));
        $this->assertSame(0, $admin->roles()->count(), 'Roles are assigned per Workspace, not globally.');
    }

    public function test_it_is_skipped_with_a_warning_outside_production_when_variables_are_missing(): void
    {
        config(['admin.email' => null, 'admin.password' => null]);

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_a_weak_password_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['admin.password' => 'Admin123*']);

        $this->expectException(RuntimeException::class);
        app(AdminUserSeeder::class)->run();
    }

    public function test_it_refuses_missing_variables_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['admin.email' => null]);

        $this->expectException(RuntimeException::class);
        app(AdminUserSeeder::class)->run();
    }

    public function test_it_accepts_a_strong_password_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['admin.password' => 'a-Long-Real-Passw0rd!']);

        app(AdminUserSeeder::class)->run();

        $this->assertTrue(Hash::check('a-Long-Real-Passw0rd!', User::where('email', 'admin@example.test')->value('password')));
    }
}

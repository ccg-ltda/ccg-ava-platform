<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates (or updates) the initial administrator from ADMIN_EMAIL / ADMIN_PASSWORD.
 * Idempotent: running it again never duplicates the user or the role.
 *
 * The administrator is a superuser (`is_superuser`), which is what lets this account enter any
 * Workspace through the pre-login/login flow.
 */
class AdminUserSeeder extends Seeder
{
    private const MIN_PRODUCTION_PASSWORD_LENGTH = 12;

    public function run(): void
    {
        $email = config('admin.email');
        $password = config('admin.password');

        if (blank($email) || blank($password)) {
            if (app()->environment('production')) {
                throw new RuntimeException('ADMIN_EMAIL and ADMIN_PASSWORD must be set to create the initial administrator.');
            }

            $this->command?->warn('AdminUserSeeder skipped: ADMIN_EMAIL / ADMIN_PASSWORD are not set.');

            return;
        }

        // The example password from .env.example is shorter than this, so it can never reach production.
        if (app()->environment('production') && strlen($password) < self::MIN_PRODUCTION_PASSWORD_LENGTH) {
            throw new RuntimeException(sprintf(
                'ADMIN_PASSWORD is too weak for production (minimum %d characters). Set a real password.',
                self::MIN_PRODUCTION_PASSWORD_LENGTH,
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::where('guard_name', 'web')->get());

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => config('admin.name'),
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        $admin->forceFill(['is_superuser' => true])->save();
    }
}

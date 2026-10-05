<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The consolidated migrations build the final schema in one go (no incremental add_* migrations). */
class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_table_the_application_uses_exists_and_unused_ones_do_not(): void
    {
        foreach (['users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs',
            'permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions',
            'organizations', 'workspaces', 'workspace_user'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}].");
        }

        $this->assertFalse(Schema::hasTable('job_batches'), 'Batches are not used.');
    }

    public function test_the_initial_create_migrations_already_contain_every_column(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['name', 'email', 'password', 'is_superuser', 'is_active']));
        $this->assertTrue(Schema::hasColumns('organizations', ['name', 'is_active']));
        $this->assertTrue(Schema::hasColumns('workspaces', ['organization_id', 'code', 'name', 'is_active']));
        $this->assertTrue(Schema::hasColumns('workspace_user', ['workspace_id', 'user_id', 'role']));
    }

    public function test_sample_accounts_with_known_passwords_are_never_seeded_outside_local_and_testing(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        app(RolesAndPermissionsSeeder::class)->run();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('roles', ['name' => 'admin']);

        $this->app->detectEnvironment(fn () => 'testing');
        app(RolesAndPermissionsSeeder::class)->run();

        $this->assertDatabaseHas('users', ['email' => 'daniel@gmail.com']);
    }
}

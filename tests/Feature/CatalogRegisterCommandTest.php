<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `catalog:register`: how a new permission or module reaches a database that already has data WITHOUT running the general seeders (which also create sample accounts in `local`,
 * every other missing permission and default plans). It registers only what is named, gives permissions to `admin` and to no other role, never touches a plan, and is idempotent.
 */
class CatalogRegisterCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        Permission::where('name', 'like', '%reminders')->delete();
        Permission::where('name', 'view-technical-activity')->delete();
        Module::where('key', 'reminders')->delete();
        Role::findOrCreate('agente', 'web')->syncPermissions(['view-dashboard']);
    }

    public function test_a_dry_run_shows_what_would_change_and_changes_nothing(): void
    {
        $this->artisan('catalog:register --permissions=view-reminders --modules=reminders --dry-run')->expectsOutputToContain('se crearía')->assertSuccessful();

        $this->assertFalse(Permission::where('name', 'view-reminders')->exists());
        $this->assertFalse(Module::where('key', 'reminders')->exists());
    }

    public function test_it_creates_only_what_is_named_gives_it_to_admin_only_and_never_touches_a_plan(): void
    {
        $plans = Plan::withCount('modules')->get()->pluck('modules_count', 'id')->all();
        $agent = Role::findByName('agente', 'web')->permissions->pluck('name')->sort()->values()->all();

        $this->artisan('catalog:register --permissions=view-reminders,create-reminders,view-technical-activity --modules=reminders')->assertSuccessful();

        $this->assertTrue(Permission::where('name', 'view-reminders')->exists());
        $this->assertFalse(Permission::where('name', 'edit-reminders')->exists(), 'only the named ones');
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('view-reminders'));
        $this->assertSame($agent, Role::findByName('agente', 'web')->permissions->pluck('name')->sort()->values()->all(), 'no other role receives anything');
        $this->assertTrue(Module::where('key', 'reminders')->exists());
        $this->assertSame($plans, Plan::withCount('modules')->get()->pluck('modules_count', 'id')->all(), 'no plan gets the module');
        $this->assertFalse(Plan::whereHas('modules', fn ($q) => $q->where('key', 'reminders'))->exists());

        $this->artisan('catalog:register --permissions=view-reminders --modules=reminders')->expectsOutputToContain('ya existe')->assertSuccessful();
        $this->assertSame(1, Permission::where('name', 'view-reminders')->count());
        $this->assertSame(1, Module::where('key', 'reminders')->count());
    }

    public function test_an_unknown_name_is_refused_and_a_commercial_permission_is_never_granted_to_admin(): void
    {
        $this->artisan('catalog:register --permissions=not-a-permission')->assertFailed();
        $this->artisan('catalog:register --modules=not-a-module')->assertFailed();

        Permission::where('name', 'view-plans')->delete();
        $this->artisan('catalog:register --permissions=view-plans')->expectsOutputToContain('sin rol')->assertSuccessful();
        $this->assertFalse(Role::findByName('admin', 'web')->hasPermissionTo('view-plans'));
    }
}

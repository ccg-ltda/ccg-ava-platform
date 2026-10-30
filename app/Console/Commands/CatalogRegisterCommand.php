<?php

namespace App\Console\Commands;

use App\Models\Module;
use App\Models\ModuleCategory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers ONLY the permissions and modules you name, on a database that already has data, without running the general seeders. Those seeders also create what a deployment
 * does not need to receive (sample accounts with known passwords in `local`, every other missing permission, the default plan for Workspaces without one). This command:
 *  - creates a permission only if it is in `config/permissions.php` and missing, and gives it to `admin` (the protected role that always holds every non-commercial permission) and to
 *    NO other role; commercial permissions are never granted here;
 *  - creates a module only if it is in `config/modules.php` `implemented` and missing, with its category if that is missing; it never touches a plan, a Workspace, a subscription or
 *    an existing module (so a module is NOT added to any plan: that is done on purpose from Planes);
 *  - forgets the permission cache. `--dry-run` shows what it would do and changes nothing. It is idempotent.
 */
#[Signature('catalog:register {--permissions= : comma separated permission names} {--modules= : comma separated module keys} {--dry-run : show what would change and change nothing}')]
#[Description('Register only the named permissions and modules of the catalog on an existing database (no general seeder, no plans, no other roles).')]
class CatalogRegisterCommand extends Command
{
    public function handle(): int
    {
        $permissions = $this->names('permissions');
        $modules = $this->names('modules');
        $dry = (bool) $this->option('dry-run');

        $unknownPermissions = array_diff($permissions, array_keys(config('permissions.permissions')));
        $unknownModules = array_diff($modules, array_keys(config('modules.implemented')));

        if ($unknownPermissions || $unknownModules) {
            $this->components->error('No están en el catálogo: '.implode(', ', [...$unknownPermissions, ...$unknownModules]));

            return self::FAILURE;
        }

        $reserved = RolesAndPermissionsSeeder::reserved();
        $admin = Role::where('name', 'admin')->where('guard_name', 'web')->first();

        foreach ($permissions as $name) {
            $exists = Permission::where('name', $name)->where('guard_name', 'web')->exists();
            $grant = ! in_array($name, $reserved, true) && $admin !== null;
            $this->line(($exists ? 'ya existe  ' : ($dry ? 'se crearía  ' : 'creado      ')).$name.($grant ? '  (admin)' : '  (sin rol)'));

            if ($dry) {
                continue;
            }

            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

            if ($grant) {
                $admin->givePermissionTo($permission);
            }
        }

        foreach ($modules as $key) {
            $exists = Module::where('key', $key)->exists();
            $this->line(($exists ? 'ya existe  ' : ($dry ? 'se crearía  ' : 'creado      ')).'módulo '.$key.'  (en ningún plan)');

            if ($dry || $exists) {
                continue;
            }

            $definition = config("modules.implemented.{$key}");
            $category = config("modules.categories.{$definition['category']}");
            $categoryModel = ModuleCategory::firstOrCreate(['key' => $definition['category']], ['name' => $category['name'], 'description' => $category['description'], 'sort_order' => $category['sort']]);
            Module::create(['key' => $key, 'module_category_id' => $categoryModel->id, 'name' => $definition['name'], 'description' => $definition['description']]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function names(string $option): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->option($option)))));
    }
}

<?php

namespace Database\Seeders;

use App\Services\ModuleCatalog;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Registers the categories and implemented modules declared in config/modules.php and the default plan. Re-runnable: it
 * only creates what is missing and never overwrites what an administrator edited (run it after declaring a new module). It
 * also gives the default plan explicitly to client Workspaces that have none, which changes nobody's access.
 */
class ModuleCatalogSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(ModuleCatalog $catalog): void
    {
        $catalog->sync();
        $catalog->ensureDefaultPlan();
        $catalog->assignDefaultPlanToUnassigned();
    }
}

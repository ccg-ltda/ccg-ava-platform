<?php

namespace App\Console\Commands;

use App\Services\E2eFixtures;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('e2e:cleanup {--dry-run : Only show what would be removed}')]
#[Description('Remove the records created by browser validations (users *@e2e.ccg.test, E2E_* Workspaces, e2e_* roles). Nothing else is touched.')]
class E2eCleanup extends Command
{
    public function handle(E2eFixtures $fixtures): int
    {
        $connection = DB::connection();
        $this->components->info("Database: {$connection->getName()} / {$connection->getDatabaseName()}");

        try {
            if ($this->option('dry-run')) {
                $this->report('Would remove', $fixtures->pending());

                return self::SUCCESS;
            }

            $this->report('Removed', $fixtures->cleanup());
            $left = array_sum($fixtures->pending());
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($left > 0) {
            $this->components->error("{$left} e2e records are still there (roles assigned to other users are kept on purpose).");

            return self::FAILURE;
        }

        $this->components->info('No e2e records left.');

        return self::SUCCESS;
    }

    /** @param  array<string, int>  $counts */
    private function report(string $label, array $counts): void
    {
        $this->table([$label, 'count'], collect($counts)->map(fn ($count, $name) => [$name, $count])->values()->all());
    }
}

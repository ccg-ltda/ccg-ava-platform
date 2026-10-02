<?php

namespace App\Console\Commands;

use App\Services\E2eFixtures;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('e2e:setup {--users=0 : Extra users to create (to test pagination)} {--superuser : Also create a superuser} {--json : Print the credentials as JSON}')]
#[Description('Create isolated fixtures (E2E_WS and *@e2e.ccg.test users) for browser validations. Remove them with e2e:cleanup.')]
class E2eSetup extends Command
{
    public function handle(E2eFixtures $fixtures): int
    {
        $connection = DB::connection();
        $this->components->info("Database: {$connection->getName()} / {$connection->getDatabaseName()}");

        try {
            $result = $fixtures->setup(max(0, (int) $this->option('users')), (bool) $this->option('superuser'));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->components->info("Workspace code: {$result['workspace']}");
        $this->table(['email', 'password', 'role', 'superuser'], array_map(
            fn ($user) => [$user['email'], $user['password'], $user['role'], $user['superuser'] ? 'yes' : 'no'],
            $result['users'],
        ));
        $this->components->warn('Run `php artisan e2e:cleanup` when you finish (it only removes e2e-tagged records).');

        return self::SUCCESS;
    }
}

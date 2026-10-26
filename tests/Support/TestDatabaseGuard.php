<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Last line of defense for the test suite. Feature tests use RefreshDatabase (migrate:fresh), which
 * would wipe whatever database the app is connected to. Inside the Docker containers the environment
 * variables point to the development PostgreSQL and win over phpunit.xml, so without this guard a
 * bare `php artisan test` would destroy the development data.
 *
 * Safe targets: SQLite in memory, or a database whose name ends in `_test` / `_testing`.
 *
 * The same environment variables also point cache, queue and session at the development Redis (the Docker containers carry CACHE_STORE=redis and
 * QUEUE_CONNECTION=redis, which win over phpunit.xml). A test run with them writes its keys to that Redis and queues jobs that the development worker
 * runs against the development database (this happened on 2026-10-20), so `assertSafeServices` refuses them too. `make test` forces the right values.
 */
class TestDatabaseGuard
{
    public static function assertSafe(string $connection, ?string $database): void
    {
        $inMemory = $connection === 'sqlite' && in_array($database, [':memory:', ''], true);
        $dedicated = $database !== null && preg_match('/_(test|testing)$/', $database) === 1;

        if ($inMemory || $dedicated) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run tests against the [%s] connection, database [%s]: it is not a test database. '.
            'Run `make test` (SQLite in memory) or point the tests to a database ending in _test.',
            $connection,
            $database ?? 'null',
        ));
    }

    /**
     * The default disk is the bucket of the development stack (S3/MinIO) inside the containers, and it wins over phpunit.xml. A test that writes or deletes
     * on it (E2eFixtures::cleanup removes `workspaces/{id}`, and the SQLite ids 1, 2... are the ones of the real Workspaces) destroys the real files, so a remote
     * default disk is refused: `make test` forces FILESYSTEM_DISK=local and TestCase fakes it.
     */
    public static function assertSafeStorage(?string $driver): void
    {
        if ($driver === null || ! in_array($driver, ['s3', 'ftp', 'sftp'], true)) {
            return;
        }

        throw new RuntimeException(
            'Refusing to run tests with a remote default filesystem disk ['.$driver.']: a test could delete real files of the development bucket. '.
            'Run `make test` (it forces FILESYSTEM_DISK=local).',
        );
    }

    /**
     * @param  array<string, ?string>  $drivers  purpose => configured driver/connection (cache, queue, session, broadcast)
     */
    public static function assertSafeServices(array $drivers): void
    {
        $shared = array_keys(array_filter($drivers, fn (?string $driver) => $driver === 'redis'));

        if ($shared === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run tests with the shared Redis as %s: it would write to the development Redis and queue jobs for the development worker. '.
            'Run `make test` (it forces CACHE_STORE=array, SESSION_DRIVER=array, QUEUE_CONNECTION=sync).',
            implode(', ', $shared),
        ));
    }
}

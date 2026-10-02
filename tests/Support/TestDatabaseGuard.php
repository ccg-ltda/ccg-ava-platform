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
}

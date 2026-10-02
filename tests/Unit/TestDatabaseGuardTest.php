<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

class TestDatabaseGuardTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function safeTargets(): array
    {
        return [
            'sqlite in memory' => ['sqlite', ':memory:'],
            'pgsql test database' => ['pgsql', 'ccg_ava_test'],
            'pgsql testing database' => ['pgsql', 'ccg_ava_testing'],
        ];
    }

    /** @return array<string, array{string, ?string}> */
    public static function unsafeTargets(): array
    {
        return [
            'development postgres' => ['pgsql', 'ccg_ava'],
            'sqlite file' => ['sqlite', '/var/www/html/database/database.sqlite'],
            'name only contains test' => ['pgsql', 'testing_ccg_ava'],
            'no database name' => ['pgsql', null],
        ];
    }

    #[DataProvider('safeTargets')]
    public function test_it_allows_test_databases(string $connection, ?string $database): void
    {
        TestDatabaseGuard::assertSafe($connection, $database);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('unsafeTargets')]
    public function test_it_refuses_anything_else(string $connection, ?string $database): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a test database');

        TestDatabaseGuard::assertSafe($connection, $database);
    }
}

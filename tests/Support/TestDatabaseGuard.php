<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Refuses to let the test suite touch a database that is not clearly disposable.
 *
 * Tests\TestCase uses RefreshDatabase, which drops and re-migrates every table. Running it
 * against a non-test database would destroy that database's data, so the guard runs in
 * createApplication(), before any trait setup (including RefreshDatabase) executes.
 */
final class TestDatabaseGuard
{
    public static function assertSafe(string $driver, ?string $database): void
    {
        if (self::isSafe($driver, $database)) {
            return;
        }

        throw new RuntimeException(sprintf(
            "Refusing to run tests against database '%s' on connection driver '%s': test databases must end with '_test' (or use sqlite :memory:). Set DB_DATABASE to a disposable *_test database.",
            (string) $database,
            $driver
        ));
    }

    private static function isSafe(string $driver, ?string $database): bool
    {
        if ($database === null || $database === '') {
            return false;
        }

        if ($driver === 'sqlite') {
            return $database === ':memory:' || str_ends_with($database, '_test.sqlite');
        }

        return str_ends_with($database, '_test');
    }
}

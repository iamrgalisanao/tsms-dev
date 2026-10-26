<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

class TestDatabaseGuardTest extends TestCase
{
    public static function allowedProvider(): array
    {
        return [
            'mysql dedicated test db' => ['mysql', 'tsms_db_test'],
            'mysql any _test suffix' => ['mysql', 'anything_test'],
            'sqlite in-memory' => ['sqlite', ':memory:'],
            'sqlite test file' => ['sqlite', '/tmp/tsms_test.sqlite'],
        ];
    }

    public static function refusedProvider(): array
    {
        return [
            'mysql dev db' => ['mysql', 'tsms_dev'],
            'mysql prod-like db' => ['mysql', 'tsms_db'],
            'mysql test prefix only' => ['mysql', 'test_tsms'],
            'mysql empty name' => ['mysql', ''],
            'mysql null name' => ['mysql', null],
            'mysql _testing suffix' => ['mysql', 'tsms_dev_testing'],
            'mysql parallel worker name' => ['mysql', 'tsms_db_test_1'],
            'sqlite non-test file' => ['sqlite', '/data/prod.sqlite'],
        ];
    }

    #[DataProvider('allowedProvider')]
    public function test_allows_disposable_test_databases(string $driver, string $database): void
    {
        TestDatabaseGuard::assertSafe($driver, $database);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('refusedProvider')]
    public function test_refuses_non_test_databases(string $driver, ?string $database): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Refusing to run tests against database '".(string) $database."' on connection driver '{$driver}'");

        TestDatabaseGuard::assertSafe($driver, $database);
    }
}

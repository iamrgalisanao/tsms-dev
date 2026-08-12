<?php

declare(strict_types=1);

namespace Tests\Support;

use PDOException;
use PDOStatement;

/**
 * Shared test double, extracted (Slice 17, T100) from
 * tests/Feature/Services/Backfill/TaxBackfillRunnerTest.php's original
 * Slice 6 regression test so it can be reused by
 * OrphanTaxReconcilerTest/OrphanTaxDeleterTest's own retry-coverage tests
 * (see slice-17-operational-watchdog-brief.md's test plan) without
 * redeclaring the same class under the same namespace twice. Purely a
 * relocation — behavior is byte-for-byte identical to the original.
 *
 * Makes the underlying PDO driver throw a *real* PDOException —
 * indistinguishable, once wrapped by Laravel's
 * Connection::runQueryCallback() into a QueryException, from a genuine
 * MySQL deadlock — on the first execute() call whose SQL matches $matcher,
 * then behaves exactly like the real PDOStatement afterward (including for
 * every later retry of the very same query). This exercises
 * DeadlockRetryService's actual QueryException-catching retry loop and
 * Laravel's real nested-transaction handling
 * (ManagesTransactions::handleTransactionException()) for real, rather than
 * mocking DeadlockRetryService or the class under test — the bug class this
 * double exists to reproduce lives specifically in how those interact
 * through the connection's genuine transaction-depth counter, which no
 * amount of mocking either class could reproduce.
 */
class DeadlockOnceOnMatchStatement extends PDOStatement
{
    /** @var null|callable(string): bool */
    public static $matcher = null;

    public static int $remainingFailures = 0;

    public static int $timesTriggered = 0;

    protected function __construct()
    {
        // PDO instantiates statement subclasses internally via
        // PDO::ATTR_STATEMENT_CLASS; nothing to set up here.
    }

    public function execute(?array $params = null): bool
    {
        if (
            self::$remainingFailures > 0
            && self::$matcher !== null
            && (self::$matcher)($this->queryString)
        ) {
            self::$remainingFailures--;
            self::$timesTriggered++;

            $exception = new PDOException(
                'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction',
                1213
            );
            // Populated exactly as a real MySQL deadlock would (mirrors
            // DeadlockRetryServiceTest::deadlockException()) so
            // QueryException::__construct's errorInfo copy and
            // DeadlockRetryService::isRetryableDeadlock()'s message check
            // both see realistic data.
            $exception->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

            throw $exception;
        }

        return parent::execute($params);
    }

    public static function reset(): void
    {
        self::$matcher = null;
        self::$remainingFailures = 0;
        self::$timesTriggered = 0;
    }
}

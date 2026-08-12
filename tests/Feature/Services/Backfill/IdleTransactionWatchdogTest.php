<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Backfill;

use App\Services\Backfill\IdleTransactionWatchdog;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;
use Throwable;

/**
 * 002-backfill-transaction-taxes, Slice 17 (T100) — operational watchdog
 * controls. See
 * specs/002-backfill-transaction-taxes/slice-17-operational-watchdog-brief.md.
 *
 * Covers IdleTransactionWatchdog::check()/sampleProcesslist()/
 * applyLowLockWaitTimeout() directly: the blocking gate genuinely detects a
 * transaction open on a distinct DB session (not merely this test's own
 * ambient RefreshDatabase transaction), passes clean with no other idle
 * transaction, the session lock-wait timeout is actually set, and
 * sampleProcesslist() never gates anything under any content.
 */
class IdleTransactionWatchdogTest extends TestCase
{
    private ?PDO $secondConnection = null;

    protected function tearDown(): void
    {
        if ($this->secondConnection !== null) {
            try {
                if ($this->secondConnection->inTransaction()) {
                    $this->secondConnection->rollBack();
                }
            } catch (Throwable $e) {
                // Best-effort cleanup only — nothing in this test's own
                // assertions depends on this connection surviving teardown.
            }

            $this->secondConnection = null;
        }

        parent::tearDown();
    }

    /**
     * A genuinely separate DB session — a real second PDO connection to the
     * same test database, deliberately NOT a nested transaction on the
     * connection this test itself runs on — with an open, uncommitted, real
     * InnoDB transaction. information_schema.INNODB_TRX only shows
     * genuinely distinct sessions, per the brief's test plan; a nested
     * transaction on the same connection would never appear as its own row.
     */
    private function openSeparateIdleTransaction(): PDO
    {
        $config = config('database.connections.mysql');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );

        $pdo = new PDO($dsn, $config['username'], $config['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET autocommit=0');

        // A bare `SELECT 1` does not necessarily register an InnoDB
        // transaction; a statement that actually touches a real InnoDB
        // table (with an explicit locking read, for certainty regardless of
        // isolation level) does.
        $pdo->exec('SELECT COUNT(*) FROM transaction_taxes FOR UPDATE');

        $this->secondConnection = $pdo;

        return $pdo;
    }

    public function test_check_blocks_when_a_genuinely_separate_connection_holds_an_open_transaction(): void
    {
        $this->openSeparateIdleTransaction();

        // Real wall-clock age (not simulated) guarantees
        // TIMESTAMPDIFF(SECOND, trx_started, NOW()) > 0 is unambiguously
        // true regardless of sub-second timing between the two statements.
        sleep(1);

        $result = (new IdleTransactionWatchdog)->check(thresholdSeconds: 0);

        $this->assertFalse($result['passed']);
        $this->assertGreaterThanOrEqual(1, $result['idle_transaction_count']);
        $this->assertNotNull($result['oldest_age_seconds']);
        $this->assertGreaterThanOrEqual(1, $result['oldest_age_seconds']);
        $this->assertNotEmpty($result['transactions']);

        foreach ($result['transactions'] as $transaction) {
            $this->assertArrayHasKey('trx_id', $transaction);
            $this->assertArrayHasKey('trx_state', $transaction);
            $this->assertArrayHasKey('age_seconds', $transaction);
            $this->assertArrayNotHasKey(
                'trx_query',
                $transaction,
                'The transactions list must never carry raw trx_query text — it may contain POS payload data.'
            );
        }
    }

    /**
     * With no other genuinely open transaction, check() passes clean. Uses
     * the default (60s) threshold rather than 0 — this test's own ambient
     * RefreshDatabase transaction may itself already be visible in
     * INNODB_TRX (setUp()'s seedMinimalLookupTables() touches real InnoDB
     * tables), but it is at most a fraction of a second old, so it can
     * never trip a 60s-idle gate.
     */
    public function test_check_passes_clean_with_no_other_open_transaction(): void
    {
        $result = (new IdleTransactionWatchdog)->check();

        $this->assertTrue($result['passed']);
        $this->assertSame(0, $result['idle_transaction_count']);
        $this->assertNull($result['oldest_age_seconds']);
        $this->assertSame([], $result['transactions']);
    }

    public function test_apply_low_lock_wait_timeout_sets_the_session_variable_from_config(): void
    {
        config()->set('tsms.backfill.lock_wait_timeout_seconds', 3);

        (new IdleTransactionWatchdog)->applyLowLockWaitTimeout();

        $value = DB::selectOne('SELECT @@session.innodb_lock_wait_timeout AS value');

        $this->assertSame(3, (int) $value->value);
    }

    public function test_apply_low_lock_wait_timeout_accepts_an_explicit_override(): void
    {
        (new IdleTransactionWatchdog)->applyLowLockWaitTimeout(7);

        $value = DB::selectOne('SELECT @@session.innodb_lock_wait_timeout AS value');

        $this->assertSame(7, (int) $value->value);
    }

    public function test_sample_processlist_never_gates_and_returns_a_bounded_summary(): void
    {
        $result = (new IdleTransactionWatchdog)->sampleProcesslist();

        $this->assertArrayHasKey('connection_count', $result);
        $this->assertArrayHasKey('longest_running_query_age_seconds', $result);
        $this->assertArrayHasKey('queries_above_threshold_count', $result);
        $this->assertArrayHasKey('threshold_seconds', $result);
        $this->assertArrayNotHasKey(
            'passed',
            $result,
            'sampleProcesslist() must never carry a pass/fail field of any kind — it never gates anything, under any content.'
        );

        // This test's own connection is itself a live entry in PROCESSLIST.
        $this->assertGreaterThanOrEqual(1, $result['connection_count']);
    }

    /**
     * Even with a genuinely idle transaction present (which WOULD fail
     * check()), sampleProcesslist() itself still returns a plain summary
     * with no pass/fail semantics — proving the two methods are truly
     * independent, per the brief's "never gates anything" requirement.
     */
    public function test_sample_processlist_is_unaffected_by_a_condition_that_would_fail_check(): void
    {
        $this->openSeparateIdleTransaction();
        sleep(1);

        $checkResult = (new IdleTransactionWatchdog)->check(thresholdSeconds: 0);
        $this->assertFalse($checkResult['passed'], 'Sanity check: this scenario must genuinely fail check().');

        $processlistResult = (new IdleTransactionWatchdog)->sampleProcesslist();

        $this->assertArrayNotHasKey('passed', $processlistResult);
        $this->assertIsInt($processlistResult['connection_count']);
        $this->assertIsInt($processlistResult['longest_running_query_age_seconds']);
        $this->assertIsInt($processlistResult['queries_above_threshold_count']);
    }
}

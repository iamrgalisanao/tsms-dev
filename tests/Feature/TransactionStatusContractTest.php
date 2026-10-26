<?php

namespace Tests\Feature;

use App\Jobs\ProcessTransactionJob;
use App\Models\Transaction;
use App\Services\TransactionValidationService;
use App\Support\Metrics;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ADR-004 slice 1: ProcessTransactionJob status contract (D2, D3, D4, D5).
 *
 * Every scenario runs twice: under the app connection's normal (non-strict) session and
 * under a STRICT_ALL_TABLES session, so an invalid enum write ('' / 'FAILED') or an
 * FK-invalid audit write surfaces as an error instead of being silently coerced.
 */
class TransactionStatusContractTest extends TestCase
{
    private const LATE_FAILURE_METRIC = 'transaction_job.late_failure_ignored';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        // Production runs with mass-assignment guarding on. Another test
        // (TokenIntrospectionTest: PosTerminal::unguard()) leaks the process-wide
        // unguarded flag, which would make the job's create() send non-fillable keys.
        Model::reguard();

        // FK reality: validation_statuses has no 'FAILED' code (ValidationStatusSeeder).
        // Defensive: remove any 'FAILED' row leaked into the shared test DB by older runs.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::table('validation_statuses')->where('code', 'FAILED')->delete();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public static function sqlModes(): array
    {
        return [
            'default session' => [false],
            'strict session' => [true],
        ];
    }

    private function inSqlMode(bool $strict, callable $scenario): void
    {
        // Rows can leak between tests in this suite (TestCase refreshApplication issue), so
        // the guard only inspects rows created by this scenario.
        $baselineId = (int) DB::table('transactions')->max('id');

        if (! $strict) {
            $scenario();
            $this->assertNoInvalidStatusWritten($baselineId);

            return;
        }

        $previous = DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;
        DB::statement("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
        try {
            $this->assertSame('STRICT_ALL_TABLES', DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m);
            $scenario();
            $this->assertNoInvalidStatusWritten($baselineId);
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$previous]);
        }
    }

    private function assertNoInvalidStatusWritten(int $baselineId): void
    {
        $this->assertGreaterThan(0, DB::table('transactions')->where('id', '>', $baselineId)->count(),
            'guard must inspect at least one transaction created by the scenario');
        $this->assertSame(0, DB::table('transactions')->where('id', '>', $baselineId)
            ->whereIn('validation_status', ['', 'FAILED'])->count(),
            "transactions.validation_status must never be '' or 'FAILED'");
        $this->assertSame(0, DB::table('transaction_validations')->where('transaction_pk', '>', $baselineId)
            ->where('status_code', 'FAILED')->count(),
            "transaction_validations.status_code must never be 'FAILED'");
    }

    private function serviceReturning(array $result): TransactionValidationService
    {
        $mock = Mockery::mock(TransactionValidationService::class);
        $mock->shouldReceive('validateTransaction')->andReturn($result);

        return $mock;
    }

    private function serviceThrowing(\Throwable $e): TransactionValidationService
    {
        $mock = Mockery::mock(TransactionValidationService::class);
        $mock->shouldReceive('validateTransaction')->andThrow($e);

        return $mock;
    }

    private function pendingTransaction(): Transaction
    {
        return Transaction::factory()->create([
            'validation_status' => Transaction::VALIDATION_STATUS_PENDING,
            'job_status' => Transaction::JOB_STATUS_QUEUED,
            'job_attempts' => 0,
        ]);
    }

    #[DataProvider('sqlModes')]
    public function test_business_invalid_ends_invalid_completed_with_invalid_audit_row(bool $strict): void
    {
        $this->inSqlMode($strict, function () {
            $tx = $this->pendingTransaction();
            $service = $this->serviceReturning([
                'valid' => false,
                'errors' => ['net_sales' => ['Net sales exceed gross sales']],
            ]);

            (new ProcessTransactionJob($tx->id))->handle($service); // must not throw

            $tx->refresh();
            $this->assertSame('INVALID', $tx->validation_status);
            $this->assertSame('COMPLETED', $tx->job_status);
            $this->assertStringContainsString('Net sales exceed gross sales', (string) $tx->last_error);
            $this->assertSame(1, (int) $tx->job_attempts);
            $this->assertNotNull($tx->completed_at);

            $validation = DB::table('transaction_validations')->where('transaction_pk', $tx->id)->sole();
            $this->assertSame('INVALID', $validation->status_code);
            $this->assertStringContainsString('Net sales exceed gross sales', (string) $validation->validation_details);

            $job = DB::table('transaction_jobs')->where('transaction_pk', $tx->id)->sole();
            $this->assertSame('COMPLETED', $job->job_status);
            $this->assertStringContainsString('Net sales exceed gross sales', (string) $job->last_error);
        });
    }

    #[DataProvider('sqlModes')]
    public function test_infrastructure_failure_keeps_pending_and_marks_job_failed(bool $strict): void
    {
        $this->inSqlMode($strict, function () {
            $tx = $this->pendingTransaction();
            $error = new \RuntimeException('DB connection lost');
            $job = new ProcessTransactionJob($tx->id);

            try {
                $job->handle($this->serviceThrowing($error));
                $this->fail('Original exception must be rethrown');
            } catch (\RuntimeException $e) {
                $this->assertSame($error, $e, 'handleError must not mask the original exception');
            }
            // The queue later reports the terminal failure through failed().
            $job->failed($error);

            $tx->refresh();
            $this->assertSame('PENDING', $tx->validation_status);
            $this->assertSame('FAILED', $tx->job_status);
            $this->assertSame('DB connection lost', $tx->last_error);
            $this->assertSame(1, (int) $tx->job_attempts, 'handle() ran once; failed() must not add attempts');

            $validation = DB::table('transaction_validations')->where('transaction_pk', $tx->id)->sole();
            $this->assertSame('PENDING', $validation->status_code);
            $this->assertStringContainsString('DB connection lost', (string) $validation->validation_details);

            $jobRow = DB::table('transaction_jobs')->where('transaction_pk', $tx->id)->sole();
            $this->assertSame('FAILED', $jobRow->job_status);
            $this->assertSame('DB connection lost', $jobRow->last_error);
        });
    }

    #[DataProvider('sqlModes')]
    public function test_late_failed_after_success_changes_nothing_and_counts_metric(bool $strict): void
    {
        $this->inSqlMode($strict, function () {
            $tx = $this->pendingTransaction();
            $job = new ProcessTransactionJob($tx->id);
            $job->handle($this->serviceReturning(['valid' => true]));

            $tx->refresh();
            $this->assertSame('VALID', $tx->validation_status);
            $this->assertSame('COMPLETED', $tx->job_status);
            $before = $tx->only(['validation_status', 'job_status', 'last_error', 'job_attempts', 'completed_at']);
            $metricBefore = (int) Metrics::get(self::LATE_FAILURE_METRIC, 0);

            $job->failed(new MaxAttemptsExceededException(
                'App\Jobs\ProcessTransactionJob has been attempted too many times.'
            ));

            $tx->refresh();
            $this->assertSame($before, $tx->only(['validation_status', 'job_status', 'last_error', 'job_attempts', 'completed_at']));
            $this->assertSame(1, (int) $tx->job_attempts);
            $this->assertSame('COMPLETED', DB::table('transaction_jobs')->where('transaction_pk', $tx->id)->value('job_status'));
            $this->assertSame('VALID', DB::table('transaction_validations')->where('transaction_pk', $tx->id)->value('status_code'));
            $this->assertSame($metricBefore + 1, (int) Metrics::get(self::LATE_FAILURE_METRIC, 0));
        });
    }

    #[DataProvider('sqlModes')]
    public function test_late_failed_after_invalid_changes_nothing(bool $strict): void
    {
        $this->inSqlMode($strict, function () {
            $tx = $this->pendingTransaction();
            $job = new ProcessTransactionJob($tx->id);
            $job->handle($this->serviceReturning(['valid' => false, 'errors' => ['x' => 'bad']]));
            $metricBefore = (int) Metrics::get(self::LATE_FAILURE_METRIC, 0);

            $job->failed(new MaxAttemptsExceededException('attempted too many times'));

            $tx->refresh();
            $this->assertSame('INVALID', $tx->validation_status);
            $this->assertSame('COMPLETED', $tx->job_status);
            $this->assertSame('bad', $tx->last_error);
            $this->assertSame($metricBefore + 1, (int) Metrics::get(self::LATE_FAILURE_METRIC, 0));
        });
    }

    #[DataProvider('sqlModes')]
    public function test_failed_on_never_run_pending_transaction_marks_job_failed_only(bool $strict): void
    {
        $this->inSqlMode($strict, function () {
            $tx = $this->pendingTransaction();
            $metricBefore = (int) Metrics::get(self::LATE_FAILURE_METRIC, 0);

            (new ProcessTransactionJob($tx->id))->failed(
                new MaxAttemptsExceededException('App\Jobs\ProcessTransactionJob has been attempted too many times.')
            );

            $tx->refresh();
            $this->assertSame('PENDING', $tx->validation_status);
            $this->assertSame('FAILED', $tx->job_status);
            $this->assertStringContainsString('attempted too many times', (string) $tx->last_error);
            $this->assertSame(0, (int) $tx->job_attempts, 'no real handle() run happened');
            $this->assertSame(0, DB::table('transaction_jobs')->where('transaction_pk', $tx->id)->count());
            $this->assertSame(0, DB::table('transaction_validations')->where('transaction_pk', $tx->id)->count());
            $this->assertSame($metricBefore, (int) Metrics::get(self::LATE_FAILURE_METRIC, 0));
        });
    }

    #[DataProvider('sqlModes')]
    public function test_job_attempts_counts_each_real_run_exactly_once(bool $strict): void
    {
        $this->inSqlMode($strict, function () {
            $tx = $this->pendingTransaction();
            $error = new \RuntimeException('timeout talking to downstream');

            // Run 1: infrastructure failure (handle catch + failed(), as the sync queue does).
            $job = new ProcessTransactionJob($tx->id);
            try {
                $job->handle($this->serviceThrowing($error));
            } catch (\RuntimeException) {
            }
            $job->failed($error);
            $this->assertSame(1, (int) $tx->fresh()->job_attempts);

            // Run 2: retry succeeds.
            (new ProcessTransactionJob($tx->id))->handle($this->serviceReturning(['valid' => true]));
            $tx->refresh();
            $this->assertSame(2, (int) $tx->job_attempts);
            $this->assertSame('VALID', $tx->validation_status);
            $this->assertSame('COMPLETED', $tx->job_status);
            $this->assertNull($tx->last_error);

            // Run 3: early exit on terminal row is not a real run.
            (new ProcessTransactionJob($tx->id))->handle($this->serviceReturning(['valid' => true]));
            $this->assertSame(2, (int) $tx->fresh()->job_attempts);
        });
    }

    #[DataProvider('sqlModes')]
    public function test_invalid_terminal_row_short_circuits(bool $strict): void
    {
        $this->inSqlMode($strict, function () {
            $tx = Transaction::factory()->create([
                'validation_status' => Transaction::VALIDATION_STATUS_INVALID,
                'job_status' => Transaction::JOB_STATUS_COMPLETED,
                'job_attempts' => 1,
                'last_error' => 'original reason',
            ]);
            $service = Mockery::mock(TransactionValidationService::class);
            $service->shouldNotReceive('validateTransaction');

            (new ProcessTransactionJob($tx->id))->handle($service);

            $tx->refresh();
            $this->assertSame('INVALID', $tx->validation_status);
            $this->assertSame('COMPLETED', $tx->job_status);
            $this->assertSame(1, (int) $tx->job_attempts);
            $this->assertSame('original reason', $tx->last_error);
            $this->assertSame(0, DB::table('transaction_jobs')->where('transaction_pk', $tx->id)->count());
            $this->assertSame(0, DB::table('transaction_validations')->where('transaction_pk', $tx->id)->count());
        });
    }

    public function test_legacy_valid_with_failed_job_keeps_valid_on_failure(): void
    {
        // Legacy TSMS-12 shape (VALID + FAILED) is not terminal per D4, but D3 still forbids
        // touching validation_status.
        $this->inSqlMode(true, function () {
            $tx = Transaction::factory()->create([
                'validation_status' => Transaction::VALIDATION_STATUS_VALID,
                'job_status' => Transaction::JOB_STATUS_FAILED,
            ]);

            (new ProcessTransactionJob($tx->id))->failed(new MaxAttemptsExceededException('too many'));

            $tx->refresh();
            $this->assertSame('VALID', $tx->validation_status);
            $this->assertSame('FAILED', $tx->job_status);
        });
    }

    /**
     * Legacy rows written by the old code: 'FAILED' coerced to '' by a non-strict session.
     */
    private function legacyBlankRow(): Transaction
    {
        $tx = $this->pendingTransaction();
        $previous = DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;
        DB::statement("SET SESSION sql_mode = ''");
        try {
            DB::table('transactions')->where('id', $tx->id)->update([
                'validation_status' => 'FAILED', // stored as '' (not in the enum)
                'job_status' => Transaction::JOB_STATUS_FAILED,
                'last_error' => 'App\\Jobs\\ProcessTransactionJob has been attempted too many times.',
                'job_attempts' => 1,
            ]);
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$previous]);
        }
        $this->assertSame('', DB::table('transactions')->where('id', $tx->id)->value('validation_status'));

        return $tx;
    }

    #[DataProvider('sqlModes')]
    public function test_legacy_blank_row_is_revalidated_to_valid(bool $strict): void
    {
        $this->inSqlMode($strict, function () {
            $tx = $this->legacyBlankRow();

            (new ProcessTransactionJob($tx->id))->handle($this->serviceReturning(['valid' => true]));

            $tx->refresh();
            $this->assertSame('VALID', $tx->validation_status);
            $this->assertSame('COMPLETED', $tx->job_status);
            $this->assertNull($tx->last_error);
            $this->assertSame(2, (int) $tx->job_attempts);
            $this->assertSame('VALID', DB::table('transaction_validations')->where('transaction_pk', $tx->id)->value('status_code'));
            $this->assertSame('COMPLETED', DB::table('transaction_jobs')->where('transaction_pk', $tx->id)->value('job_status'));
        });
    }

    #[DataProvider('sqlModes')]
    public function test_legacy_blank_row_is_revalidated_to_invalid(bool $strict): void
    {
        $this->inSqlMode($strict, function () {
            $tx = $this->legacyBlankRow();

            (new ProcessTransactionJob($tx->id))->handle($this->serviceReturning([
                'valid' => false, 'errors' => ['vat' => 'VAT mismatch'],
            ]));

            $tx->refresh();
            $this->assertSame('INVALID', $tx->validation_status);
            $this->assertSame('COMPLETED', $tx->job_status);
            $this->assertSame('VAT mismatch', $tx->last_error);
            $this->assertSame('INVALID', DB::table('transaction_validations')->where('transaction_pk', $tx->id)->value('status_code'));
        });
    }

    #[DataProvider('sqlModes')]
    public function test_success_landing_between_failure_read_and_write_is_not_downgraded(bool $strict): void
    {
        $this->inSqlMode($strict, function () {
            $tx = $this->pendingTransaction();
            $metricBefore = (int) Metrics::get(self::LATE_FAILURE_METRIC, 0);

            // Simulate a concurrent successful run committing right after handleError() has
            // read the row (still PENDING) and before it writes FAILED.
            $fired = false;
            DB::listen(function ($query) use (&$fired, $tx) {
                if ($fired || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                    || ! str_contains($query->sql, '`transactions`')) {
                    return;
                }
                $fired = true;
                DB::table('transactions')->where('id', $tx->id)->update([
                    'validation_status' => Transaction::VALIDATION_STATUS_VALID,
                    'job_status' => Transaction::JOB_STATUS_COMPLETED,
                    'last_error' => null,
                ]);
            });

            (new ProcessTransactionJob($tx->id))->failed(
                new MaxAttemptsExceededException('App\\Jobs\\ProcessTransactionJob has been attempted too many times.')
            );

            $this->assertTrue($fired, 'race injection must have run');
            $tx->refresh();
            $this->assertSame('VALID', $tx->validation_status);
            $this->assertSame('COMPLETED', $tx->job_status);
            $this->assertNull($tx->last_error);
            $this->assertSame($metricBefore + 1, (int) Metrics::get(self::LATE_FAILURE_METRIC, 0));
        });
    }

    /**
     * Proves the strict harness is meaningful: the writes the old code made are rejected.
     */
    public function test_strict_session_rejects_legacy_failed_writes(): void
    {
        $tx = $this->pendingTransaction();
        $previous = DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;
        DB::statement("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
        try {
            try {
                DB::table('transactions')->where('id', $tx->id)->update(['validation_status' => 'FAILED']);
                $this->fail("Strict session must reject validation_status='FAILED'");
            } catch (QueryException $e) {
                $this->assertStringContainsString('validation_status', $e->getMessage());
            }

            try {
                DB::table('transaction_validations')->insert([
                    'transaction_pk' => $tx->id,
                    'status_code' => 'FAILED',
                    'created_at' => now(),
                ]);
                $this->fail("FK must reject transaction_validations.status_code='FAILED'");
            } catch (QueryException $e) {
                $this->assertStringContainsString('foreign key constraint', strtolower($e->getMessage()));
            }
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$previous]);
        }
        $this->assertSame('PENDING', $tx->fresh()->validation_status);
    }
}

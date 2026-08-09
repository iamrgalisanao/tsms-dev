<?php

namespace Tests\Unit\Services;

use App\Services\DeadlockRetryService;
use App\Support\Metrics;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PDOException;
use Tests\TestCase;
use Tests\Traits\FakesMetricsDistributionRedis;

/**
 * WU3 (T053): DeadlockRetryService::withDeadlockRetry() instrumentation.
 *
 * Covers the counters (db.deadlock_retry.attempted/succeeded/exhausted/
 * non_retryable), the timing samples (delay_ms/operation_ms/
 * total_recovery_ms) recorded via App\Support\Metrics::sample() (WU2), and
 * the must-fix logging constraint: $e->getMessage() on a QueryException is
 * salted with the full interpolated SQL text and bindings by Laravel's
 * QueryException::formatMessage(), so none of this class's log lines may
 * ever contain it.
 *
 * Uses the real Cache-backed counter store (array driver in testing, see
 * .env.testing) for incr()/get() assertions, and
 * FakesMetricsDistributionRedis (same double MetricsDistributionTest uses)
 * for sample()/percentile() since CI provisions no real Redis service.
 */
class DeadlockRetryServiceTest extends TestCase
{
    use FakesMetricsDistributionRedis;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tsms.metrics.distribution.redis_connection', 'default');
        config()->set('tsms.metrics.distribution.key_prefix', 'metrics:dist:');
        config()->set('tsms.metrics.distribution.sample_cap', 1000);
        config()->set('tsms.metrics.distribution.sample_ttl_seconds', 3600);
        config()->set('tsms.metrics.distribution.cardinality_budget', 200);
        config()->set('tsms.metrics.distribution.cardinality_ttl_seconds', 3600);
        config()->set('tsms.metrics.distribution.allowed_dimensions', ['route', 'shard']);

        $this->fakeMetricsDistributionRedis();

        // Isolate counters between test methods — CacheMetricStore uses the
        // array cache driver, which persists for the process but not across
        // tests unless explicitly cleared.
        Cache::flush();
    }

    public function test_successful_retry_increments_attempted_and_succeeded_and_records_timing_samples(): void
    {
        $service = new DeadlockRetryService;
        $calls = 0;

        $result = $service->withDeadlockRetry(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw $this->deadlockException();
            }

            return 'ok';
        }, maxAttempts: 5, wrapInTransaction: false);

        $this->assertSame('ok', $result);
        $this->assertSame(2, $calls);

        $this->assertSame(1, Metrics::get('db.deadlock_retry.attempted'));
        $this->assertSame(1, Metrics::get('db.deadlock_retry.succeeded'));
        $this->assertSame(0, Metrics::get('db.deadlock_retry.exhausted'));
        $this->assertSame(0, Metrics::get('db.deadlock_retry.non_retryable'));

        $delay = Metrics::percentile('db.deadlock_retry.delay_ms', 100);
        $operation = Metrics::percentile('db.deadlock_retry.operation_ms', 100);
        $total = Metrics::percentile('db.deadlock_retry.total_recovery_ms', 100);

        $this->assertSame(1, $delay['sample_count']);
        $this->assertNotNull($delay['value']);
        $this->assertGreaterThan(0, $delay['value']);

        $this->assertSame(1, $operation['sample_count']);
        $this->assertNotNull($operation['value']);
        $this->assertGreaterThanOrEqual(0, $operation['value']);

        $this->assertSame(1, $total['sample_count']);
        $this->assertNotNull($total['value']);
        $this->assertGreaterThan(0, $total['value']);
    }

    public function test_exhausted_retries_increments_exhausted_counter_and_rethrows_original_exception(): void
    {
        $service = new DeadlockRetryService;
        $calls = 0;
        $thrown = $this->deadlockException();

        try {
            $service->withDeadlockRetry(function () use (&$calls, $thrown) {
                $calls++;
                throw $thrown;
            }, maxAttempts: 3, wrapInTransaction: false);

            $this->fail('Expected QueryException to propagate after retries were exhausted.');
        } catch (QueryException $e) {
            // Existing behavior preserved: the very same exception instance
            // that kept failing is the one that ultimately propagates.
            $this->assertSame($thrown, $e);
        }

        $this->assertSame(3, $calls);
        $this->assertSame(3, Metrics::get('db.deadlock_retry.attempted'));
        $this->assertSame(1, Metrics::get('db.deadlock_retry.exhausted'));
        $this->assertSame(0, Metrics::get('db.deadlock_retry.succeeded'));
        $this->assertSame(0, Metrics::get('db.deadlock_retry.non_retryable'));

        $total = Metrics::percentile('db.deadlock_retry.total_recovery_ms', 100);
        $this->assertSame(1, $total['sample_count']);
        $this->assertNotNull($total['value']);

        // Only 2 backoff sleeps happen before the 3rd (final) attempt gives
        // up without sleeping again.
        $delay = Metrics::percentile('db.deadlock_retry.delay_ms', 100);
        $this->assertSame(2, $delay['sample_count']);
    }

    public function test_non_retryable_exception_increments_non_retryable_counter_and_propagates_immediately_without_retry(): void
    {
        $service = new DeadlockRetryService;
        $calls = 0;
        $thrown = $this->nonRetryableException();

        try {
            $service->withDeadlockRetry(function () use (&$calls, $thrown) {
                $calls++;
                throw $thrown;
            }, maxAttempts: 5, wrapInTransaction: false);

            $this->fail('Expected the non-retryable QueryException to propagate.');
        } catch (QueryException $e) {
            $this->assertSame($thrown, $e);
        }

        $this->assertSame(1, $calls, 'A non-retryable exception must not trigger any retry.');
        $this->assertSame(0, Metrics::get('db.deadlock_retry.attempted'));
        $this->assertSame(0, Metrics::get('db.deadlock_retry.succeeded'));
        $this->assertSame(0, Metrics::get('db.deadlock_retry.exhausted'));
        $this->assertSame(1, Metrics::get('db.deadlock_retry.non_retryable'));

        $delay = Metrics::percentile('db.deadlock_retry.delay_ms', 100);
        $this->assertSame(0, $delay['sample_count']);
        $this->assertNull($delay['value']);
    }

    public function test_logged_context_never_contains_the_raw_sql_laden_exception_message(): void
    {
        // Log::spy() + shouldHaveReceived() only asserts "at least one call
        // matched" — it won't fail if a *different* call at the same level
        // leaked the forbidden text. Capture every info/warning/error call
        // instead so every single one can be inspected below.
        $captured = [];
        $capture = function (string $message, array $context = []) use (&$captured) {
            $captured[] = [$message, $context];
        };

        Log::shouldReceive('info')->andReturnUsing($capture);
        Log::shouldReceive('warning')->andReturnUsing($capture);
        Log::shouldReceive('error')->andReturnUsing($capture);

        $service = new DeadlockRetryService;
        $calls = 0;

        $service->withDeadlockRetry(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw $this->deadlockException();
            }

            return 'ok';
        }, maxAttempts: 5, wrapInTransaction: false);

        $this->assertNotEmpty($captured, 'Expected at least one log line from the retry-then-succeed path.');

        // The raw QueryException::getMessage() interpolates the full SQL
        // text and the bound "42.5" value (see deadlockException() below) —
        // neither may ever appear in a logged message or context array.
        $forbiddenFragments = [
            'update `transactions`',
            'net_sales',
            '42.5',
            'SQL:',
        ];

        foreach ($captured as [$message, $context]) {
            $serialized = $message.' '.json_encode($context);

            foreach ($forbiddenFragments as $fragment) {
                $this->assertStringNotContainsString(
                    $fragment,
                    $serialized,
                    "Forbidden fragment '{$fragment}' leaked into logged output: {$serialized}"
                );
            }
        }
    }

    public function test_sql_state_and_driver_error_code_are_extracted_from_a_realistic_query_exception(): void
    {
        Log::spy();

        $service = new DeadlockRetryService;
        $thrown = $this->deadlockException();

        try {
            $service->withDeadlockRetry(function () use ($thrown) {
                throw $thrown;
            }, maxAttempts: 1, wrapInTransaction: false);
        } catch (QueryException $e) {
            // maxAttempts: 1 means the very first failure is already
            // "exhausted" — attempt(1) is not < maxAttempts(1).
        }

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context) {
                return ($context['sql_state'] ?? null) === '40001'
                    && ($context['driver_error_code'] ?? null) === '1213';
            });
    }

    public function test_operation_label_reflects_the_external_caller_not_this_service(): void
    {
        $service = new DeadlockRetryService;
        $calls = 0;

        Log::spy();

        $service->withDeadlockRetry(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw $this->deadlockException();
            }

            return 'ok';
        }, maxAttempts: 5, wrapInTransaction: false);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) {
                return isset($context['operation'])
                    && str_contains($context['operation'], self::class);
            });
    }

    /**
     * Realistic deadlock QueryException fixture: errorInfo is set exactly
     * as PDO/QueryException would populate it (QueryException's
     * constructor copies $previous->errorInfo whenever $previous is a
     * PDOException — see
     * vendor/laravel/framework/src/Illuminate/Database/QueryException.php),
     * and getMessage() reproduces the real formatMessage() interpolation
     * (SQL text + bindings appended) so tests can assert that text is
     * never logged.
     */
    private function deadlockException(): QueryException
    {
        $previous = new PDOException(
            'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction',
            1213
        );
        $previous->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

        return new QueryException(
            'mysql',
            'update `transactions` set `net_sales` = ? where `id` = ?',
            [42.5, 999],
            $previous
        );
    }

    private function nonRetryableException(): QueryException
    {
        $previous = new PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'ABC123' for key 'transactions_transaction_id_unique'",
            23000
        );
        $previous->errorInfo = ['23000', 1062, "Duplicate entry 'ABC123' for key 'transactions_transaction_id_unique'"];

        return new QueryException(
            'mysql',
            'insert into `transactions` (`transaction_id`) values (?)',
            ['ABC123'],
            $previous
        );
    }
}

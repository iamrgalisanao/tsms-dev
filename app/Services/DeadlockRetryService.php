<?php

namespace App\Services;

use App\Support\Metrics;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Retries DB operations on deadlocks/lock-waits, and (WU3, T053) makes that
 * retry behavior observable.
 *
 * Important scope note: this instrument only sees DB pressure that actually
 * surfaced as one of the three matched error phrases below. It is
 * *retry-observed* pressure, not total DB pressure — zero retries does NOT
 * mean zero lock contention; contention that never raised a matching
 * exception here (e.g. resolved entirely within engine-level lock waiting,
 * or under a driver/timeout combination that doesn't produce one of these
 * messages) is invisible to it. A proactive sampler (`SHOW ENGINE INNODB
 * STATUS`, `information_schema.processlist`) would close that gap but is
 * explicitly out of scope for this work unit — tracked as a possible later
 * enhancement, not implemented here.
 *
 * Logging constraint (must-fix, see specs/001-100-tenant-resilience/plan.md
 * WU3): Laravel's QueryException::formatMessage() (see
 * vendor/laravel/framework/src/Illuminate/Database/QueryException.php)
 * interpolates the full SQL text and bindings directly into
 * $e->getMessage(). Nothing in this class ever logs that string. Instead,
 * SQLSTATE and the driver-specific error code are read from
 * QueryException::$errorInfo, which QueryException's constructor copies
 * from the wrapped PDOException's own $errorInfo array whenever the
 * previous exception is a PDOException (see the same vendor file, lines
 * ~41-54: `if ($previous instanceof PDOException) { $this->errorInfo =
 * $previous->errorInfo; }`). $e->getMessage() is still read internally by
 * isRetryableDeadlock() to classify the exception (pre-existing behavior,
 * unchanged) — that string is used for a str_contains() check only and is
 * never written to a log or metric.
 */
class DeadlockRetryService
{
    /**
     * Retry a database transaction on deadlocks, serialization failures, and lock wait timeouts.
     *
     * Instrumentation only — the retry count, backoff formula, and
     * exceptions thrown/returned are byte-for-byte identical to the
     * pre-WU3 implementation. See the class docblock for the
     * retry-observed-pressure caveat and the logging constraint.
     */
    public function withDeadlockRetry(callable $callback, int $maxAttempts = 5, bool $wrapInTransaction = true)
    {
        $attempt = 0;
        $operation = $this->callerOperation();

        // Set the first time a retryable exception is caught. Only
        // meaningful once at least one retry has happened, which is why
        // total_recovery_ms is only ever sampled downstream of that.
        $recoveryStartedAt = null;
        $attemptStartedAt = microtime(true);

        retry:
        try {
            $result = $wrapInTransaction
                ? DB::transaction($callback, 1)
                : $callback();

            if ($attempt > 0) {
                $this->recordRecovered($operation, $attempt, $maxAttempts, $attemptStartedAt, $recoveryStartedAt);
            }

            return $result;
        } catch (QueryException $e) {
            [$sqlState, $driverErrorCode] = $this->extractErrorInfo($e);

            if (! $this->isRetryableDeadlock($e)) {
                Metrics::incr('db.deadlock_retry.non_retryable');

                Log::warning('DeadlockRetryService: non-retryable query exception encountered', $this->logContext(
                    $operation,
                    $attempt + 1,
                    $maxAttempts,
                    $e->getConnectionName(),
                    ['sql_state' => $sqlState, 'driver_error_code' => $driverErrorCode]
                ));

                throw $e;
            }

            $attempt++;
            $recoveryStartedAt ??= microtime(true);

            // Counts every retryable deadlock/lock-wait detected, including
            // the final one that ends up exhausting retries below — i.e.
            // attempted >= succeeded + exhausted, not a per-cycle count.
            Metrics::incr('db.deadlock_retry.attempted');

            if ($attempt < $maxAttempts) {
                $delayMicros = random_int(50000, 150000) * $attempt;
                $delayMs = $delayMicros / 1000;

                Metrics::sample('db.deadlock_retry.delay_ms', $delayMs);

                Log::info('DeadlockRetryService: deadlock/lock-wait detected, retrying', $this->logContext(
                    $operation,
                    $attempt,
                    $maxAttempts,
                    $e->getConnectionName(),
                    ['delay_ms' => round($delayMs, 2), 'sql_state' => $sqlState, 'driver_error_code' => $driverErrorCode]
                ));

                usleep($delayMicros);
                $attemptStartedAt = microtime(true);
                goto retry;
            }

            $totalMs = (microtime(true) - $recoveryStartedAt) * 1000;
            Metrics::incr('db.deadlock_retry.exhausted');
            Metrics::sample('db.deadlock_retry.total_recovery_ms', $totalMs);

            Log::error('DeadlockRetryService: deadlock retries exhausted, rethrowing', $this->logContext(
                $operation,
                $attempt,
                $maxAttempts,
                $e->getConnectionName(),
                ['total_recovery_ms' => round($totalMs, 2), 'sql_state' => $sqlState, 'driver_error_code' => $driverErrorCode]
            ));

            throw $e;
        }
    }

    /**
     * Records the terminal "it worked after retrying" outcome: the
     * succeeded counter, the two timing samples, and a matching log line.
     *
     * operation_ms is the duration of the single call that finally
     * succeeded (the wrapped DB::transaction()/callback() invocation on
     * this attempt only) — not the cumulative time across all attempts.
     * total_recovery_ms is that cumulative view: wall-clock time from the
     * first retryable failure to this success, including every backoff
     * sleep and every retried call in between.
     */
    private function recordRecovered(
        ?string $operation,
        int $attempt,
        int $maxAttempts,
        float $attemptStartedAt,
        ?float $recoveryStartedAt
    ): void {
        $operationMs = (microtime(true) - $attemptStartedAt) * 1000;
        $totalMs = $recoveryStartedAt !== null
            ? (microtime(true) - $recoveryStartedAt) * 1000
            : $operationMs;

        Metrics::incr('db.deadlock_retry.succeeded');
        Metrics::sample('db.deadlock_retry.operation_ms', $operationMs);
        Metrics::sample('db.deadlock_retry.total_recovery_ms', $totalMs);

        Log::info('DeadlockRetryService: retry succeeded after deadlock/lock-wait', $this->logContext(
            $operation,
            $attempt,
            $maxAttempts,
            config('database.default'),
            ['operation_ms' => round($operationMs, 2), 'total_recovery_ms' => round($totalMs, 2)]
        ));
    }

    /**
     * Unchanged detection logic from the pre-WU3 implementation — same
     * three str_contains() phrases, same reliance on $e->getMessage() for
     * classification only. Extracted to a named method purely for
     * readability; behavior is byte-for-byte identical.
     */
    private function isRetryableDeadlock(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Deadlock found when trying to get lock') ||
            str_contains($message, 'SQLSTATE[40001]') ||
            str_contains($message, 'Lock wait timeout exceeded');
    }

    /**
     * SQLSTATE + driver-specific error code from QueryException's inherited
     * PDOException::$errorInfo (see class docblock for how it gets there).
     * Never reads $e->getMessage(). Returns [null, null] when errorInfo
     * wasn't populated (e.g. a hand-built exception in a test that didn't
     * set it) rather than guessing.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function extractErrorInfo(QueryException $e): array
    {
        $errorInfo = $e->errorInfo ?? null;

        $sqlState = $errorInfo[0] ?? null;
        $driverErrorCode = isset($errorInfo[1]) ? (string) $errorInfo[1] : null;

        return [$sqlState, $driverErrorCode];
    }

    /**
     * Best-effort "operation" label for log context: the immediate caller's
     * Class::method, taken from a bounded backtrace lookup — never from
     * request/tenant/payload data. Falls back to null (omitted from the
     * logged context by logContext()) when it can't be determined, e.g.
     * called from a top-level closure with no enclosing method.
     *
     * Frame indices, verified against actual PHP debug_backtrace()
     * semantics (each frame's function/class describe what was *called*,
     * while file/line describe *where that call was made from* — so
     * naming the enclosing method of our caller requires looking one frame
     * further up than it first appears):
     *   [0] => this method itself (callerOperation)
     *   [1] => withDeadlockRetry (the method that called us)
     *   [2] => the external caller of withDeadlockRetry() — what we want.
     */
    private function callerOperation(): ?string
    {
        $frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2] ?? null;
        $function = $frame['function'] ?? null;

        if (! $function) {
            return null;
        }

        return isset($frame['class']) ? "{$frame['class']}::{$function}" : $function;
    }

    /**
     * Best-effort correlation ID for log context. This is NOT "no
     * correlation ID exists in the system" — ProcessTransactionIntakeJob
     * (a queue job, one of this class's two real callers via
     * TransactionIngestService::ingest()) does carry a trace_id (WU1) and
     * threads it into its own log lines. The accurate statement is
     * narrower: no correlation ID reaches THIS class's parameters today,
     * because TransactionIngestService::ingest(array $payload) doesn't
     * currently forward trace_id into the payload it builds, and
     * DeadlockRetryService::withDeadlockRetry() has no parameter for one.
     * Threading it through would mean touching TransactionIngestService.php
     * and the job's payload-building code — outside WU3's declared file
     * list (app/Services/DeadlockRetryService.php only) — so it's left as
     * a follow-up, not ruled out as impossible. Rather than fabricate a
     * value or add a new request-context dependency to this DB-layer class
     * just for this work unit, this reads the canonical `correlation_id`
     * request attribute (per App\Support\LogContext / WU1's
     * AttachCorrelationId) only when a real HTTP request happens to be
     * bound — which correctly resolves to null on both of this class's
     * actual current call paths (the queue job and the
     * ReconcileStrandedIntake console command, neither of which runs
     * inside an HTTP request lifecycle), and would pick up a value for
     * free if a future synchronous/HTTP call site is added.
     */
    private function correlationId(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof \Illuminate\Http\Request
            ? $request->attributes->get('correlation_id')
            : null;
    }

    /**
     * Bounded log context shared by every log line in this class: operation,
     * attempt, max_attempts, connection, correlation_id (when available),
     * plus whatever call-specific bounded fields are passed in $extra
     * (delay_ms, sql_state, driver_error_code, operation_ms,
     * total_recovery_ms). Null values are omitted rather than logged as
     * noise. Never includes SQL text, bindings, credentials, or tenant
     * payload data.
     */
    private function logContext(?string $operation, int $attempt, int $maxAttempts, ?string $connection, array $extra = []): array
    {
        $context = [
            'operation' => $operation,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'connection' => $connection,
            'correlation_id' => $this->correlationId(),
        ];

        return array_filter(array_merge($context, $extra), fn ($value) => $value !== null);
    }
}

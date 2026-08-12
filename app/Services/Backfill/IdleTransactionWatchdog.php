<?php

declare(strict_types=1);

namespace App\Services\Backfill;

use Illuminate\Support\Facades\DB;

/**
 * 002-backfill-transaction-taxes, Slice 17 (T100) — operational watchdog
 * controls. See
 * specs/002-backfill-transaction-taxes/slice-17-operational-watchdog-brief.md.
 *
 * Safety instrumentation only, wrapped around this feature's existing
 * mutation entry points (TaxBackfillRunner::apply(), ArchiveOrphanTaxRows's
 * three --apply branches) — it changes no outcome any of those paths
 * produces, only whether/how safely a mutating statement is allowed to run.
 * Three independent methods, mirroring TaxBackfillPreflightChecker's
 * "related but independent checks" precedent:
 *
 *  - check(): the blocking gate. Queries `information_schema.INNODB_TRX`
 *    (transaction start time), NOT `SHOW PROCESSLIST`/
 *    `information_schema.PROCESSLIST` (connection/query state) — the
 *    2026-08-10 incident (research.md R9) this brief exists to guard against
 *    was specifically about an open transaction's *age*, which only
 *    `INNODB_TRX.trx_started` gives directly.
 *  - sampleProcesslist(): a non-blocking evidence snapshot, automating what
 *    quickstart.md's Step 5 currently asks a human to watch live (`SHOW
 *    PROCESSLIST`). Never gates anything — its result has no `passed` field
 *    and callers must never derive one from it.
 *  - applyLowLockWaitTimeout(): session-scoped `innodb_lock_wait_timeout`,
 *    intended to be called once per command invocation, immediately after
 *    check() passes. Per the brief's Grounding section, this is only safe
 *    paired with DeadlockRetryService retry coverage on the mutation paths
 *    it protects — a low timeout without retry coverage just makes a
 *    mutating statement fail faster, not yield more gracefully.
 */
class IdleTransactionWatchdog
{
    /**
     * Evidence cap on the `transactions` list check() returns — the count
     * itself (idle_transaction_count) is never capped, only how many
     * individual rows are echoed back for human inspection.
     */
    protected const MAX_REPORTED_TRANSACTIONS = 50;

    /**
     * A "low informational threshold" (brief's sampleProcesslist() wording)
     * for sampleProcesslist()'s queries_above_threshold_count — purely
     * descriptive/observability, never a pass/fail condition.
     */
    protected const PROCESSLIST_INFORMATIONAL_THRESHOLD_SECONDS = 5;

    /**
     * The blocking gate: are there any InnoDB transactions currently open
     * longer than $thresholdSeconds (default
     * config('tsms.backfill.idle_transaction_threshold_seconds', 60))?
     * `passed = (idle_transaction_count === 0)`.
     *
     * `trx_query` is deliberately selected (matching the brief's prescribed
     * SQL exactly) but never included in the returned `transactions` list —
     * it may contain POS payload data, so only trx_id/trx_state/age_seconds
     * are echoed back.
     *
     * @return array{
     *     idle_transaction_count: int,
     *     oldest_age_seconds: int|null,
     *     transactions: list<array{trx_id: mixed, trx_state: string, age_seconds: int}>,
     *     passed: bool,
     * }
     */
    public function check(?int $thresholdSeconds = null): array
    {
        $threshold = $thresholdSeconds ?? (int) config('tsms.backfill.idle_transaction_threshold_seconds', 60);

        $rows = collect(DB::select(
            'SELECT trx_id, trx_state, trx_started, trx_mysql_thread_id, trx_query, '.
            'TIMESTAMPDIFF(SECOND, trx_started, NOW()) AS age_seconds '.
            'FROM information_schema.INNODB_TRX '.
            'WHERE TIMESTAMPDIFF(SECOND, trx_started, NOW()) > ? '.
            'ORDER BY trx_started ASC',
            [$threshold]
        ));

        $idleTransactionCount = $rows->count();

        $oldestAgeSeconds = $rows->isNotEmpty()
            ? (int) $rows->first()->age_seconds
            : null;

        $transactions = $rows->take(self::MAX_REPORTED_TRANSACTIONS)
            ->map(fn ($row) => [
                'trx_id' => $row->trx_id,
                'trx_state' => $row->trx_state,
                'age_seconds' => (int) $row->age_seconds,
            ])
            ->values()
            ->all();

        return [
            'idle_transaction_count' => $idleTransactionCount,
            'oldest_age_seconds' => $oldestAgeSeconds,
            'transactions' => $transactions,
            'passed' => $idleTransactionCount === 0,
        ];
    }

    /**
     * Non-blocking evidence snapshot: a summary of
     * `information_schema.PROCESSLIST` (connection count, longest-running
     * query age, count of queries above a low informational threshold).
     * Never gates anything — the caller must never derive a pass/fail
     * decision from this result, under any content.
     *
     * @return array{
     *     connection_count: int,
     *     longest_running_query_age_seconds: int,
     *     queries_above_threshold_count: int,
     *     threshold_seconds: int,
     * }
     */
    public function sampleProcesslist(): array
    {
        $rows = collect(DB::select('SELECT `TIME` AS time_seconds FROM information_schema.PROCESSLIST'));

        $ages = $rows->map(fn ($row) => (int) $row->time_seconds);

        return [
            'connection_count' => $rows->count(),
            'longest_running_query_age_seconds' => $ages->max() ?? 0,
            'queries_above_threshold_count' => $ages->filter(
                fn (int $age) => $age > self::PROCESSLIST_INFORMATIONAL_THRESHOLD_SECONDS
            )->count(),
            'threshold_seconds' => self::PROCESSLIST_INFORMATIONAL_THRESHOLD_SECONDS,
        ];
    }

    /**
     * Session-scoped `innodb_lock_wait_timeout`, read from
     * config('tsms.backfill.lock_wait_timeout_seconds', 3) by default.
     * Intended to be called once per command invocation (not per chunk — a
     * Laravel CLI command holds one persistent connection for its duration),
     * immediately after check() passes, before any chunked mutation begins.
     *
     * Only safe paired with DeadlockRetryService retry coverage on the
     * mutation path it precedes (see this class's own docblock and the
     * brief's Grounding section) — this method does not itself add any
     * retry behavior.
     */
    public function applyLowLockWaitTimeout(?int $seconds = null): void
    {
        $value = $seconds ?? (int) config('tsms.backfill.lock_wait_timeout_seconds', 3);

        DB::statement('SET SESSION innodb_lock_wait_timeout = ?', [$value]);
    }
}

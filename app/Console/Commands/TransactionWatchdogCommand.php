<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTransactionJob;
use App\Models\Transaction;
use App\Notifications\TransactionRetryCapReachedAlert;
use App\Services\IngestionQueueRouter;
use App\Services\NotificationService;
use App\Support\Metrics;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ADR-004 D7 transaction watchdog (scheduled as 'transactions-watchdog').
 *
 *  1. PENDING + QUEUED rows older than requeue_after (and younger than max_pending) are
 *     re-dispatched only when the ProcessTransactionJob unique lock can be acquired;
 *     otherwise the job is still queued or running and the row is skipped_inflight.
 *  2. PENDING + job FAILED rows are re-dispatched with exponential backoff
 *     (requeue_after * 2^n minutes since updated_at) up to max_requeue_attempts. The count
 *     lives in the cache (txn:watchdog_requeues:{id}, 7 days), never in job_attempts. At the
 *     cap the row is left as is: warning log and metric per row, flagged so it is reported
 *     once, and ONE summary admin alert per run (total + up to 20 refs). At most
 *     max_failed_scan (default 1000) PENDING+FAILED rows are scanned per run, in id order,
 *     resuming from a rotating cursor (txn:watchdog_failed_cursor) that wraps to 0.
 *  3. PENDING rows older than max_pending get job_status=FAILED and last_error through a
 *     conditional atomic update that only matches validation_status=PENDING. It never
 *     writes validation_status.
 *
 * Re-dispatch goes through IngestionQueueRouter (crc32 tenant shards). job_attempts is
 * never touched. At most 200 actions per branch per run, scanned in id order.
 */
class TransactionWatchdogCommand extends Command
{
    protected $signature = 'tsms:transactions-watchdog';

    protected $description = 'Re-dispatch or time out stuck PENDING transactions (ADR-004 D7)';

    public const BATCH = 200;

    public const TIMEOUT_ERROR = 'Watchdog timeout exceeded';

    public const MAX_ALERT_ROWS = 20;

    private const CACHE_TTL_SECONDS = 7 * 24 * 3600;

    private array $counters = [
        'requeued' => 0,
        'skipped_inflight' => 0,
        'cap_reached' => 0,
        'timed_out' => 0,
    ];

    private IngestionQueueRouter $router;

    private Dispatcher $dispatcher;

    private Cache $cache;

    private NotificationService $notifications;

    /** Cap-reached rows collected during this run for the single summary alert. */
    private array $capRows = [];

    public static function requeueCountKey(int $transactionPk): string
    {
        return 'txn:watchdog_requeues:'.$transactionPk;
    }

    public const FAILED_CURSOR_KEY = 'txn:watchdog_failed_cursor';

    private static bool $cursorWarningLogged = false;

    public static function capAlertedKey(int $transactionPk): string
    {
        return 'txn:watchdog_cap_alerted:'.$transactionPk;
    }

    public function handle(IngestionQueueRouter $router, Dispatcher $dispatcher, Cache $cache, NotificationService $notifications): int
    {
        $this->router = $router;
        $this->dispatcher = $dispatcher;
        $this->cache = $cache;
        $this->notifications = $notifications;
        $this->counters = array_fill_keys(array_keys($this->counters), 0);
        $this->capRows = [];

        $cfg = config('tsms.transactions.watchdog');
        if (! $cfg || ! ($cfg['enabled'] ?? false)) {
            return self::SUCCESS;
        }

        $now = now();
        $requeueAfter = max(1, (int) ($cfg['requeue_after_minutes'] ?? 10));
        $requeueCutoff = $now->copy()->subMinutes($requeueAfter);
        $maxPendingCutoff = $now->copy()->subMinutes((int) ($cfg['max_pending_minutes'] ?? 60));
        $maxRequeues = max(0, (int) ($cfg['max_requeue_attempts'] ?? 3));

        $this->requeueStaleQueued($requeueCutoff, $maxPendingCutoff);
        $this->retryFailed($now, $requeueAfter, $maxRequeues, max(1, (int) ($cfg['max_failed_scan'] ?? 1000)));
        $this->timeOutStalePending($maxPendingCutoff);
        $this->sendCapSummary($maxRequeues);

        foreach ($this->counters as $name => $value) {
            if ($value > 0) {
                Metrics::incr('transaction_watchdog.'.$name, $value);
            }
        }

        if (array_sum($this->counters) > 0) {
            Log::warning('[Watchdog] Transaction watchdog actions', $this->counters + [
                'requeue_cutoff' => $requeueCutoff->toDateTimeString(),
                'max_pending_cutoff' => $maxPendingCutoff->toDateTimeString(),
                'max_requeue_attempts' => $maxRequeues,
            ]);
        }

        $this->line(json_encode($this->counters));

        return self::SUCCESS;
    }

    /** Branch 1: PENDING + QUEUED, requeue_after < age <= max_pending. */
    private function requeueStaleQueued(Carbon $requeueCutoff, Carbon $maxPendingCutoff): void
    {
        $budget = self::BATCH;
        $this->candidates()
            ->where('validation_status', Transaction::VALIDATION_STATUS_PENDING)
            ->where('job_status', Transaction::JOB_STATUS_QUEUED)
            ->where('created_at', '<', $requeueCutoff)
            ->where('created_at', '>=', $maxPendingCutoff)
            ->chunkById(self::BATCH, function (Collection $rows) use (&$budget) {
                foreach ($rows as $txn) {
                    if ($budget-- <= 0) {
                        return false;
                    }
                    $this->dispatchIfFree($txn);
                }
            });
    }

    /** Branch 2: PENDING + job FAILED, exponential backoff, capped. */
    private function retryFailed(Carbon $now, int $requeueAfter, int $maxRequeues, int $maxScan): void
    {
        $budget = self::BATCH;
        $scanned = 0;
        $stopped = false;

        // Rotating cursor: capped/flagged rows stay PENDING+FAILED, so a fixed "first N ids"
        // window would starve later rows. Each run resumes after the last scanned id and wraps
        // to 0 once the remaining rows fit in one scan.
        $cursor = $this->readFailedCursor();
        $lastId = $cursor;

        $this->candidates()
            ->where('validation_status', Transaction::VALIDATION_STATUS_PENDING)
            ->where('job_status', Transaction::JOB_STATUS_FAILED)
            ->where('updated_at', '<=', $now->copy()->subMinutes($requeueAfter))
            ->where('id', '>', $cursor)
            ->chunkById(self::BATCH, function (Collection $rows) use (&$budget, &$scanned, &$stopped, &$lastId, $maxScan, $now, $requeueAfter, $maxRequeues) {
                foreach ($rows as $txn) {
                    if ($scanned >= $maxScan) {
                        $stopped = true;

                        return false;
                    }
                    $scanned++;
                    $lastId = $txn->id;
                    $count = (int) $this->cache->get(self::requeueCountKey($txn->id), 0);

                    // Backoff since the last failure (updated_at): requeue_after * 2^n minutes.
                    // At the cap the same wait applies, so the last re-dispatch gets its chance.
                    $waitMinutes = $requeueAfter * (2 ** min($count, 16));
                    if ($txn->updated_at === null || $txn->updated_at->gt($now->copy()->subMinutes($waitMinutes))) {
                        continue;
                    }

                    if ($count >= $maxRequeues) {
                        $this->handleCapReached($txn, $count);

                        continue;
                    }

                    if ($budget-- <= 0) {
                        $lastId = $txn->id - 1; // resume at this row next run
                        $stopped = true;

                        return false;
                    }
                    if ($this->dispatchIfFree($txn)) {
                        $this->cache->put(self::requeueCountKey($txn->id), $count + 1, self::CACHE_TTL_SECONDS);
                    }
                }
            });

        $this->writeFailedCursor($stopped ? $lastId : 0);
    }

    private function readFailedCursor(): int
    {
        try {
            return max(0, (int) $this->cache->get(self::FAILED_CURSOR_KEY, 0));
        } catch (\Throwable $e) {
            $this->warnCursorOnce($e);

            return 0;
        }
    }

    private function writeFailedCursor(int $id): void
    {
        try {
            $this->cache->put(self::FAILED_CURSOR_KEY, $id, self::CACHE_TTL_SECONDS);
        } catch (\Throwable $e) {
            $this->warnCursorOnce($e);
        }
    }

    private function warnCursorOnce(\Throwable $e): void
    {
        if (! self::$cursorWarningLogged) {
            self::$cursorWarningLogged = true;
            Log::warning('[Watchdog] failed-row cursor unavailable; scanning from id 0', ['error' => $e->getMessage()]);
        }
    }

    /** Branch 3: PENDING older than max_pending → job FAILED only. */
    private function timeOutStalePending(Carbon $maxPendingCutoff): void
    {
        $budget = self::BATCH;
        $this->candidates()
            ->where('validation_status', Transaction::VALIDATION_STATUS_PENDING)
            ->where(fn ($q) => $q->whereNull('job_status')->orWhere('job_status', '!=', Transaction::JOB_STATUS_FAILED))
            ->where('created_at', '<', $maxPendingCutoff)
            ->chunkById(self::BATCH, function (Collection $rows) use (&$budget, $maxPendingCutoff) {
                foreach ($rows as $txn) {
                    if ($budget-- <= 0) {
                        return false;
                    }

                    // Conditional atomic update: re-checks PENDING in the WHERE so a row that
                    // reached VALID/INVALID + COMPLETED meanwhile is never matched, and only
                    // job_status/last_error are written (never validation_status/job_attempts).
                    // This may mark a job that is still in flight as FAILED; if that run later
                    // succeeds it finalizes the row to VALID/INVALID + COMPLETED (ADR-004 D4
                    // still holds: no failure path downgrades a final row).
                    $affected = Transaction::query()
                        ->whereKey($txn->id)
                        ->where('validation_status', Transaction::VALIDATION_STATUS_PENDING)
                        ->where(fn ($q) => $q->whereNull('job_status')->orWhere('job_status', '!=', Transaction::JOB_STATUS_FAILED))
                        ->where('created_at', '<', $maxPendingCutoff)
                        ->update([
                            'job_status' => Transaction::JOB_STATUS_FAILED,
                            'last_error' => self::TIMEOUT_ERROR,
                        ]);

                    if ($affected > 0) {
                        $this->counters['timed_out']++;
                        $this->fireUpdated($txn->id);
                    }
                }
            });
    }

    private function candidates()
    {
        return Transaction::query()
            ->select(['id', 'transaction_id', 'tenant_id', 'terminal_id', 'job_status', 'last_error', 'created_at', 'updated_at']);
    }

    /**
     * Dispatch only when the job's unique lock is free. The lock acquired here is the one the
     * queued job holds until it completes (CallQueuedHandler releases it), exactly as a normal
     * ShouldBeUnique dispatch would.
     */
    private function dispatchIfFree(Transaction $txn): bool
    {
        $job = new ProcessTransactionJob($txn->id);
        $lock = new UniqueLock($this->cache);

        if (! $lock->acquire($job)) {
            $this->counters['skipped_inflight']++;

            return false;
        }

        try {
            $tenantId = $txn->tenant_id ?? optional($txn->terminal)->tenant_id ?? 0;
            $this->dispatcher->dispatchToQueue($job->onQueue($this->router->processingQueueForTenant($tenantId)));
        } catch (\Throwable $e) {
            $lock->release($job);
            Log::error('[Watchdog] re-dispatch failed', [
                'transaction_pk' => $txn->id,
                'transaction_id' => $txn->transaction_id,
                'tenant_id' => $txn->tenant_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $this->counters['requeued']++;

        return true;
    }

    private function handleCapReached(Transaction $txn, int $count): void
    {
        $alertKey = self::capAlertedKey($txn->id);
        if ($this->cache->has($alertKey)) {
            return; // already reported for this row
        }

        // Do not report while the last re-dispatch is still queued or running.
        $job = new ProcessTransactionJob($txn->id);
        $lock = new UniqueLock($this->cache);
        if (! $lock->acquire($job)) {
            $this->counters['skipped_inflight']++;

            return;
        }
        $lock->release($job);

        $this->cache->put($alertKey, true, self::CACHE_TTL_SECONDS);
        $this->counters['cap_reached']++;

        $ref = [
            'transaction_pk' => $txn->id,
            'transaction_id' => $txn->transaction_id,
            'tenant_id' => $txn->tenant_id,
            'terminal_id' => $txn->terminal_id,
            'last_error_class' => self::errorClass($txn->last_error),
        ];
        Log::warning('[Watchdog] retry cap reached', $ref + ['requeue_attempts' => $count]);
        $this->capRows[] = $ref;
    }

    /** One summary admin alert per run, listing at most MAX_ALERT_ROWS rows. */
    private function sendCapSummary(int $maxRequeues): void
    {
        if ($this->capRows === []) {
            return;
        }

        try {
            $this->notifications->notifyAdmins(new TransactionRetryCapReachedAlert(
                count($this->capRows),
                array_slice($this->capRows, 0, self::MAX_ALERT_ROWS),
                $maxRequeues,
            ));
        } catch (\Throwable $e) {
            Log::error('[Watchdog] retry cap alert failed', ['total' => count($this->capRows), 'error' => $e->getMessage()]);
        }
    }

    /** Coarse category of last_error for alerts (no raw messages or payload content). */
    public static function errorClass(?string $lastError): string
    {
        $e = (string) $lastError;

        return match (true) {
            $e === '' => 'none',
            str_contains($e, self::TIMEOUT_ERROR) => 'watchdog_timeout',
            str_contains($e, 'timed out') => 'job_timeout',
            str_contains($e, 'attempted too many times') => 'max_attempts',
            default => 'exception',
        };
    }

    /**
     * The query-builder update bypasses Eloquent events; re-fire 'updated' so the model's
     * write hook (webapp tenant_version bump) runs, as ProcessTransactionJob::handleError does.
     */
    private function fireUpdated(int $id): void
    {
        try {
            $fresh = Transaction::find($id);
            if ($fresh) {
                Transaction::getEventDispatcher()?->dispatch('eloquent.updated: '.Transaction::class, $fresh);
            }
        } catch (\Throwable $e) {
            Log::error('[Watchdog] failed firing updated event', ['transaction_pk' => $id, 'error' => $e->getMessage()]);
        }
    }
}

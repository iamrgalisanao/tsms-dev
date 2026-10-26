<?php

namespace Tests\Feature;

use App\Console\Commands\TransactionWatchdogCommand;
use App\Jobs\ProcessTransactionJob;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\TransactionRetryCapReachedAlert;
use App\Services\IngestionQueueRouter;
use App\Support\Metrics;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ADR-004 slice 2 (D7): TransactionWatchdogCommand.
 *
 * Rows can leak between tests in this suite (TestCase refreshApplication issue), so every
 * assertion is scoped to the rows a test creates (pushed jobs are matched by uniqueId).
 */
class TransactionWatchdogTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();

        config([
            'tsms.transactions.watchdog.enabled' => true,
            'tsms.transactions.watchdog.requeue_after_minutes' => 10,
            'tsms.transactions.watchdog.max_pending_minutes' => 60,
            'tsms.transactions.watchdog.max_requeue_attempts' => 3,
            'tsms.processing.shard_count' => 8,
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function transaction(array $attrs, int $createdMinutesAgo, ?int $updatedMinutesAgo = null): Transaction
    {
        $tx = Transaction::factory()->create($attrs + ['job_attempts' => 0]);
        DB::table('transactions')->where('id', $tx->id)->update([
            'created_at' => now()->subMinutes($createdMinutesAgo),
            'updated_at' => now()->subMinutes($updatedMinutesAgo ?? $createdMinutesAgo),
        ]);

        return $tx->fresh();
    }

    private function pushedFor(Transaction $tx)
    {
        return Queue::pushed(ProcessTransactionJob::class, fn ($job) => $job->uniqueId() === 'txn:'.$tx->id);
    }

    private function lock(): UniqueLock
    {
        return new UniqueLock(app(Cache::class));
    }

    private function runWatchdog(): void
    {
        $this->artisan('tsms:transactions-watchdog')->assertExitCode(0);
    }

    public function test_stale_queued_row_with_held_unique_lock_is_skipped(): void
    {
        $tx = $this->transaction(['validation_status' => 'PENDING', 'job_status' => 'QUEUED'], 20);
        $this->assertTrue($this->lock()->acquire(new ProcessTransactionJob($tx->id)), 'simulate an in-flight job');
        $before = (int) Metrics::get('transaction_watchdog.skipped_inflight', 0);

        $this->runWatchdog();

        $this->assertCount(0, $this->pushedFor($tx));
        $tx->refresh();
        $this->assertSame(0, (int) $tx->job_attempts);
        $this->assertSame('PENDING', $tx->validation_status);
        $this->assertSame('QUEUED', $tx->job_status);
        $this->assertGreaterThanOrEqual($before + 1, (int) Metrics::get('transaction_watchdog.skipped_inflight', 0));
    }

    public function test_stale_queued_row_with_free_lock_is_queued_once_on_crc32_shard(): void
    {
        $tx = $this->transaction(['validation_status' => 'PENDING', 'job_status' => 'QUEUED'], 20);
        $expectedQueue = 'transaction-processing:s'.(crc32((string) $tx->tenant_id) % 8);
        $this->assertSame($expectedQueue, app(IngestionQueueRouter::class)->processingQueueForTenant($tx->tenant_id));

        $this->runWatchdog();
        $this->runWatchdog(); // lock now held by the queued job → no second dispatch

        $pushed = $this->pushedFor($tx);
        $this->assertCount(1, $pushed);
        $this->assertSame($expectedQueue, $pushed->first()->queue);
        $this->assertFalse($this->lock()->acquire(new ProcessTransactionJob($tx->id)), 'queued job holds the unique lock');
        $tx->refresh();
        $this->assertSame(0, (int) $tx->job_attempts, 'watchdog never touches job_attempts');
        $this->assertSame('QUEUED', $tx->job_status);
    }

    public function test_young_queued_row_is_not_requeued(): void
    {
        $tx = $this->transaction(['validation_status' => 'PENDING', 'job_status' => 'QUEUED'], 5);
        $this->runWatchdog();
        $this->assertCount(0, $this->pushedFor($tx));
    }

    public function test_pending_failed_row_respects_backoff_then_cap_alerts_once(): void
    {
        $tx = $this->transaction(['validation_status' => 'PENDING', 'job_status' => 'FAILED', 'last_error' => 'boom'], 300, 5);
        $countKey = TransactionWatchdogCommand::requeueCountKey($tx->id);
        $touch = fn (int $minutesAgo) => DB::table('transactions')->where('id', $tx->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);
        $finishJob = fn () => $this->lock()->release(new ProcessTransactionJob($tx->id));

        // n=0: needs 10 minutes since the last failure.
        $this->runWatchdog();
        $this->assertCount(0, $this->pushedFor($tx));
        $touch(11);
        $this->runWatchdog();
        $this->assertCount(1, $this->pushedFor($tx));
        $this->assertSame(1, (int) cache()->get($countKey));

        // n=1: the job ran and failed again; needs 20 minutes.
        $finishJob();
        $touch(15);
        $this->runWatchdog();
        $this->assertCount(1, $this->pushedFor($tx));
        $touch(21);
        $this->runWatchdog();
        $this->assertCount(2, $this->pushedFor($tx));
        $this->assertSame(2, (int) cache()->get($countKey));

        // n=2: needs 40 minutes.
        $finishJob();
        $touch(41);
        $this->runWatchdog();
        $this->assertCount(3, $this->pushedFor($tx));
        $this->assertSame(3, (int) cache()->get($countKey));

        // Cap (3) reached: while the last dispatch is in flight nothing is reported.
        $touch(81);
        $this->runWatchdog();
        Notification::assertNothingSent();

        $finishJob();
        $before = $tx->fresh()->only(['validation_status', 'job_status', 'last_error', 'job_attempts']);
        $capMetric = (int) Metrics::get('transaction_watchdog.cap_reached', 0);
        $this->runWatchdog();
        $this->runWatchdog();

        $this->assertCount(3, $this->pushedFor($tx), 'no dispatch beyond the cap');
        Notification::assertSentToTimes($this->admin, TransactionRetryCapReachedAlert::class, 1);
        Notification::assertSentTo($this->admin, TransactionRetryCapReachedAlert::class,
            fn ($n) => collect($n->rows)->contains('transaction_pk', $tx->id) && $n->maxRequeueAttempts === 3);
        $this->assertSame($capMetric + 1, (int) Metrics::get('transaction_watchdog.cap_reached', 0));
        $this->assertSame($before, $tx->fresh()->only(['validation_status', 'job_status', 'last_error', 'job_attempts']));
        $this->assertSame(0, (int) $tx->fresh()->job_attempts);
    }

    public function test_several_capped_rows_send_one_summary_alert_per_run(): void
    {
        $rows = [];
        for ($i = 0; $i < 22; $i++) {
            $tx = $this->transaction(['validation_status' => 'PENDING', 'job_status' => 'FAILED',
                'last_error' => 'App\\Jobs\\ProcessTransactionJob has been attempted too many times.'], 600, 200);
            cache()->put(TransactionWatchdogCommand::requeueCountKey($tx->id), 3, 3600);
            $rows[] = $tx;
        }
        $ids = collect($rows)->pluck('id');

        $this->runWatchdog();
        $this->runWatchdog(); // already flagged: no second summary

        Notification::assertSentToTimes($this->admin, TransactionRetryCapReachedAlert::class, 1);
        $sent = Notification::sent($this->admin, TransactionRetryCapReachedAlert::class)->first();
        $this->assertGreaterThanOrEqual(22, $sent->totalCount, 'total includes every capped row');
        $this->assertCount(TransactionWatchdogCommand::MAX_ALERT_ROWS, $sent->rows);
        $first = $sent->rows[0];
        $this->assertSame(['transaction_pk', 'transaction_id', 'tenant_id', 'terminal_id', 'last_error_class'], array_keys($first));
        if ($ids->contains($first['transaction_pk'])) {
            $this->assertSame('max_attempts', $first['last_error_class']);
        }
        foreach ($rows as $tx) {
            $this->assertTrue(cache()->has(TransactionWatchdogCommand::capAlertedKey($tx->id)));
            $this->assertCount(0, $this->pushedFor($tx));
            $this->assertSame('FAILED', $tx->fresh()->job_status);
        }
    }

    public function test_failed_row_scan_is_bounded_per_run(): void
    {
        config(['tsms.transactions.watchdog.max_failed_scan' => 1]);
        $baselineMax = (int) DB::table('transactions')->max('id');
        $a = $this->transaction(['validation_status' => 'PENDING', 'job_status' => 'FAILED'], 300, 200);
        $b = $this->transaction(['validation_status' => 'PENDING', 'job_status' => 'FAILED'], 300, 200);
        // Leaked PENDING+FAILED rows from other tests may sit before ours in id order.
        $earlier = DB::table('transactions')->where('id', '<=', $baselineMax)
            ->where('validation_status', 'PENDING')->where('job_status', 'FAILED')->exists();

        $this->runWatchdog();

        $this->assertCount(0, $this->pushedFor($b), 'only the first scanned row is processed');
        $this->assertCount($earlier ? 0 : 1, $this->pushedFor($a));
    }

    public function test_failed_row_cursor_rotates_so_capped_rows_do_not_starve_later_rows(): void
    {
        $limit = 3;
        config(['tsms.transactions.watchdog.max_failed_scan' => $limit]);
        $capped = [];
        for ($i = 0; $i < 5; $i++) {
            $tx = $this->transaction(['validation_status' => 'PENDING', 'job_status' => 'FAILED'], 600, 200);
            cache()->put(TransactionWatchdogCommand::requeueCountKey($tx->id), 3, 3600);
            cache()->put(TransactionWatchdogCommand::capAlertedKey($tx->id), true, 3600); // inert, already reported
            $capped[] = $tx;
        }
        $high = $this->transaction(['validation_status' => 'PENDING', 'job_status' => 'FAILED'], 600, 200);

        // Every PENDING+FAILED row at or below ours (leaked rows from other tests included).
        $total = DB::table('transactions')->where('id', '<=', $high->id)
            ->where('validation_status', 'PENDING')->where('job_status', 'FAILED')->count();
        $maxRuns = (int) ceil($total / $limit) + 1;

        $runs = 0;
        $cursors = [];
        while ($runs < $maxRuns && count($this->pushedFor($high)) === 0) {
            $this->runWatchdog();
            $runs++;
            $cursors[] = (int) cache()->get(TransactionWatchdogCommand::FAILED_CURSOR_KEY);
        }

        $this->assertCount(1, $this->pushedFor($high), "high-id row re-dispatched within {$maxRuns} runs");
        $this->assertGreaterThan(1, $runs, 'the first run alone cannot reach it (capped rows fill the window)');
        foreach ($capped as $tx) {
            $this->assertCount(0, $this->pushedFor($tx));
        }

        // Keep running until the cursor wraps back to 0.
        for ($i = 0; $i < $maxRuns && end($cursors) !== 0; $i++) {
            $this->runWatchdog();
            $cursors[] = (int) cache()->get(TransactionWatchdogCommand::FAILED_CURSOR_KEY);
        }
        $this->assertSame(0, end($cursors), 'cursor wraps once the remaining rows fit in one scan');
        $this->assertCount(1, $this->pushedFor($high), 'lock held by the queued job: no second dispatch');
    }

    public function test_max_pending_sets_job_failed_only_and_never_touches_final_rows(): void
    {
        $stuck = $this->transaction(['validation_status' => 'PENDING', 'job_status' => 'PROCESSING', 'job_attempts' => 1], 120);
        $queued = $this->transaction(['validation_status' => 'PENDING', 'job_status' => 'QUEUED'], 120);
        $valid = $this->transaction(['validation_status' => 'VALID', 'job_status' => 'COMPLETED', 'last_error' => null], 120);
        $invalid = $this->transaction(['validation_status' => 'INVALID', 'job_status' => 'COMPLETED', 'last_error' => 'bad'], 120);
        $cols = ['validation_status', 'job_status', 'last_error', 'job_attempts', 'completed_at', 'updated_at'];
        $raw = fn (Transaction $t) => (array) DB::table('transactions')->where('id', $t->id)->first($cols);
        $validBefore = $raw($valid);
        $invalidBefore = $raw($invalid);
        $timedOut = (int) Metrics::get('transaction_watchdog.timed_out', 0);

        $this->runWatchdog();

        foreach ([[$stuck, 1], [$queued, 0]] as [$tx, $attempts]) {
            $tx->refresh();
            $this->assertSame('PENDING', $tx->validation_status, 'validation_status is never written');
            $this->assertSame('FAILED', $tx->job_status);
            $this->assertSame(TransactionWatchdogCommand::TIMEOUT_ERROR, $tx->last_error);
            $this->assertSame($attempts, (int) $tx->job_attempts);
            $this->assertCount(0, $this->pushedFor($tx), 'past max_pending: no re-dispatch in the same run');
        }
        $this->assertSame($validBefore, $raw($valid));
        $this->assertSame($invalidBefore, $raw($invalid));
        $this->assertGreaterThanOrEqual($timedOut + 2, (int) Metrics::get('transaction_watchdog.timed_out', 0));
    }

    public function test_disabled_watchdog_does_nothing(): void
    {
        config(['tsms.transactions.watchdog.enabled' => false]);
        $tx = $this->transaction(['validation_status' => 'PENDING', 'job_status' => 'QUEUED'], 120);

        $this->runWatchdog();

        $this->assertCount(0, $this->pushedFor($tx));
        $this->assertSame('QUEUED', $tx->fresh()->job_status);
    }
}

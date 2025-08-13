<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;
use App\Services\WebAppForwardingService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    // This is a placeholder for any scheduled tasks you want to run
    // You can add your custom logic here

    Log::info('Laravel scheduler tick: cron is running');
})->everyMinute();

// --------------------------------------------------------------------------
// Transaction pruning: remove stale PENDING (stuck) & aged FAILED transactions
// Runs every hour; uses configurable retention in config('tsms.transactions').
// --------------------------------------------------------------------------
Schedule::call(function () {
    $cfg = config('tsms.transactions');
    if (!($cfg['enable_pruning'] ?? true)) {
        return; // disabled
    }

    $failedDays = $cfg['prune_failed_after_days'] ?? 14;
    $pendingMinutes = $cfg['prune_pending_after_minutes'] ?? 180;

    $failedCutoff = now()->subDays($failedDays);
    $pendingCutoff = now()->subMinutes($pendingMinutes);

    $failedQuery = \App\Models\Transaction::where('validation_status', 'FAILED')
        ->where('created_at', '<', $failedCutoff);
    $stalePendingQuery = \App\Models\Transaction::where('validation_status', 'PENDING')
        ->where('created_at', '<', $pendingCutoff);

    $failedCount = (clone $failedQuery)->count();
    $pendingCount = (clone $stalePendingQuery)->count();

    $deletedFailed = 0; $deletedPending = 0;
    if ($failedCount > 0) { $deletedFailed = $failedQuery->delete(); }
    if ($pendingCount > 0) { $deletedPending = $stalePendingQuery->delete(); }

    if ($deletedFailed > 0 || $deletedPending > 0) {
        Log::info('[Prune] Transactions pruned', [
            'deleted_failed' => $deletedFailed,
            'deleted_stale_pending' => $deletedPending,
            'failed_cutoff' => $failedCutoff->toDateTimeString(),
            'pending_cutoff' => $pendingCutoff->toDateTimeString(),
        ]);
    }

    // Metric-style log (counts post-prune)
    $pendingNow = \App\Models\Transaction::where('validation_status','PENDING')->count();
    $failedNow = \App\Models\Transaction::where('validation_status','FAILED')->count();
    Log::info('[Prune] Transaction inventory snapshot', [
        'pending_remaining' => $pendingNow,
        'failed_remaining' => $failedNow,
    ]);
})->hourly()->name('transactions-prune')->withoutOverlapping()->onOneServer();

// --------------------------------------------------------------------------
// Transaction Watchdog: requeue or fail stuck PENDING transactions
// Runs every 5 minutes. Logic:
//  - Re-dispatch PENDING + QUEUED older than requeue_after_minutes (but younger than max_pending)
//  - If PENDING older than max_pending_minutes -> mark FAILED (timeout) & log
//  - Respects max_requeue_attempts using job_attempts counter
// --------------------------------------------------------------------------
Schedule::call(function () {
    $cfg = config('tsms.transactions.watchdog');
    if (!$cfg || !($cfg['enabled'] ?? false)) { return; }

    $now = now();
    $requeueCutoff = $now->clone()->subMinutes($cfg['requeue_after_minutes'] ?? 10);
    $maxPendingCutoff = $now->clone()->subMinutes($cfg['max_pending_minutes'] ?? 60);
    $maxRequeues = $cfg['max_requeue_attempts'] ?? 2;

    $requeued = 0; $failed = 0; $skipped = 0;

    // Re-dispatch candidates: still PENDING and queued, older than requeue cutoff but not past max pending cutoff
    $candidates = \App\Models\Transaction::where('validation_status', 'PENDING')
        ->where('job_status', 'QUEUED')
        ->whereBetween('created_at', [$maxPendingCutoff, $requeueCutoff]) // between window
        ->limit(200)
        ->get();

    foreach ($candidates as $txn) {
        if (($txn->job_attempts ?? 0) >= $maxRequeues) { $skipped++; continue; }
        \App\Jobs\ProcessTransactionJob::dispatch($txn->id)->afterCommit();
        $txn->job_attempts = ($txn->job_attempts ?? 0) + 1;
        $txn->save();
        $requeued++;
    }

    // Fail hard-timeout stale pending beyond maxPendingCutoff (older than threshold)
    $stale = \App\Models\Transaction::where('validation_status','PENDING')
        ->where('created_at','<',$maxPendingCutoff)
        ->limit(200)
        ->get();

    foreach ($stale as $txn) {
        $txn->validation_status = 'FAILED';
        $txn->job_status = 'FAILED';
        $txn->last_error = 'Watchdog timeout exceeded';
        $txn->completed_at = now();
        $txn->save();
        $failed++;
    }

    if ($requeued || $failed) {
        Log::warning('[Watchdog] Transaction watchdog actions', [
            'requeued' => $requeued,
            'failed_stale' => $failed,
            'skipped_due_to_attempts' => $skipped,
            'requeue_cutoff' => $requeueCutoff->toDateTimeString(),
            'max_pending_cutoff' => $maxPendingCutoff->toDateTimeString(),
        ]);
    }
})->everyFiveMinutes()->name('transactions-watchdog')->withoutOverlapping()->onOneServer();



// WebApp Transaction Forwarding - Every 5 minutes (DI version)
Schedule::call(function () {
    $svc = app(WebAppForwardingService::class);
    $result = $svc->forwardUnsentTransactions();
    Log::info('[Cron] WebApp forwarding run', ['result' => $result]);
})
    ->everyFiveMinutes()
    ->name('webapp-transaction-forwarding')
    ->withoutOverlapping()
    ->onOneServer()
    ->when(fn () => config('tsms.web_app.enabled', true));

// Health check for WebApp forwarding - Every hour
Schedule::call(function () {
    try {
        $service = app(\App\Services\WebAppForwardingService::class);
        $stats = $service->getForwardingStats();
        
        // Log health status
        Log::info('WebApp forwarding health check', [
            'unforwarded_transactions' => $stats['unforwarded_transactions'],
            'pending_forwards' => $stats['pending_forwards'],
            'failed_forwards' => $stats['failed_forwards'],
            'circuit_breaker_open' => $stats['circuit_breaker']['is_open'],
            'circuit_breaker_failures' => $stats['circuit_breaker']['failures'],
        ]);
        
        // Alert on circuit breaker open
        if ($stats['circuit_breaker']['is_open']) {
            Log::warning('WebApp forwarding circuit breaker is open', $stats['circuit_breaker']);
        }
        
        // Alert on high failure count
        if ($stats['failed_forwards'] > 50) {
            Log::warning('High number of failed WebApp forwards', [
                'failed_count' => $stats['failed_forwards']
            ]);
        }
        
        // Alert on old pending forwards
        $oldPending = \App\Models\WebappTransactionForward::pending()
            ->where('created_at', '<', now()->subHour())
            ->count();
            
        if ($oldPending > 0) {
            Log::warning('Old pending WebApp forwards detected', [
                'old_pending_count' => $oldPending
            ]);
        }

    } catch (\Exception $e) {
        Log::error('WebApp forwarding health check failed', [
            'error' => $e->getMessage()
        ]);
    }
})->hourly()->name('webapp-forwarding-health-check')->onOneServer();

// Cleanup old completed forwards - Daily at 2 AM
Schedule::call(function () {
    try {
        $cleanupDays = config('tsms.performance.cleanup_completed_after_days', 30);
        $failedCleanupDays = config('tsms.performance.cleanup_failed_after_days', 7);
        
        if (config('tsms.performance.enable_auto_cleanup', true)) {
            // Cleanup old completed forwards
            $completedDeleted = \App\Models\WebappTransactionForward::completed()
                ->where('completed_at', '<', now()->subDays($cleanupDays))
                ->delete();
            
            // Cleanup old failed forwards that have exhausted retries
            $failedDeleted = \App\Models\WebappTransactionForward::failed()
                ->where('updated_at', '<', now()->subDays($failedCleanupDays))
                ->where('attempts', '>=', 'max_attempts')
                ->delete();
            
            if ($completedDeleted > 0 || $failedDeleted > 0) {
                Log::info('WebApp forwarding cleanup completed', [
                    'completed_deleted' => $completedDeleted,
                    'failed_deleted' => $failedDeleted,
                ]);
            }
        }
    } catch (\Exception $e) {
        Log::error('WebApp forwarding cleanup failed', [
            'error' => $e->getMessage()
        ]);
    }
})->dailyAt('02:00')->name('webapp-forwarding-cleanup')->onOneServer();

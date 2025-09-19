<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use App\Models\WebappTransactionForward;
use App\Services\WebAppForwardingService;
use App\Models\PosTerminal;

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

    // Metric-style log (counts post-prune) with breakdown
    $pendingNow = \App\Models\Transaction::where('validation_status','PENDING')->count();
    $failedNow = \App\Models\Transaction::where('validation_status','FAILED')->count();

    // Pending forwarding: VALID transactions that have not been COMPLETED in WebApp forwarding
    $pendingForwarding = \App\Models\Transaction::where('validation_status', 'VALID')
        ->whereDoesntHave('webappForward', function ($q) {
            $q->where('status', WebappTransactionForward::STATUS_COMPLETED);
        })
        ->count();

    Log::info('[Prune] Transaction inventory snapshot', [
        'pending_remaining'       => $pendingNow,            // validation_status=PENDING
        'failed_remaining'        => $failedNow,             // validation_status=FAILED
        'pending_forwarding'      => $pendingForwarding,     // VALID but not yet forwarded
        'forwarding_pending'      => WebappTransactionForward::pending()->count(),
        'forwarding_completed'    => WebappTransactionForward::completed()->count(),
        'forwarding_failed'       => WebappTransactionForward::failed()->count(),
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

// --------------------------------------------------------------------------
// POS Terminal Idle Monitor (log-only phase)
// Runs every N minutes when enabled. Detects terminals that have been idle
// beyond configured thresholds, logs idle and recovery events with dedupe.
// --------------------------------------------------------------------------
Schedule::call(function () {
    $cfg = config('tsms.terminals.idle_monitor');
    if (!$cfg || !($cfg['enabled'] ?? false)) { return; }

    $now = now();
    $scanInterval = (int) ($cfg['scan_interval_minutes'] ?? 5);
    if ($scanInterval > 1) {
        // Honor env-configured scan interval by skipping non-matching minutes
        if (($now->minute % $scanInterval) !== 0) {
            return; // not our tick
        }
    }
    $dedupeTtl = (int) ($cfg['dedupe_ttl_seconds'] ?? 1800);
    $defaultIdle = (int) ($cfg['idle_after_seconds_default'] ?? 3600);
    $multiplier = (int) ($cfg['multiplier_of_heartbeat'] ?? 3);

    $scanned = 0; $idleNew = 0; $recovered = 0; $errors = 0;
    $summaryCfg = $cfg['summary_details'] ?? [];
    $includeChanged = (bool) ($summaryCfg['include_terminals'] ?? true);
    $terminalsCap = (int) ($summaryCfg['terminals_cap'] ?? 25);
    $hasIpColumn = Schema::hasColumn('pos_terminals', 'ip_address');
    $changedTerminals = [];
    // Track per-tenant aggregates if feature is enabled
    $perTenantCfg = $cfg['per_tenant_summary'] ?? [];
    $perTenantEnabled = (bool) ($perTenantCfg['enabled'] ?? false);
    $perTenantOnlyNonzero = (bool) ($perTenantCfg['only_nonzero'] ?? true);
    $tenantAgg = $perTenantEnabled ? [] : null;

    // Consider only active terminals; guard by schema to avoid missing columns
    $hasIsActive = Schema::hasColumn('pos_terminals', 'is_active');
    $hasStatusId = Schema::hasColumn('pos_terminals', 'status_id');
    $hasStatus = Schema::hasColumn('pos_terminals', 'status');

    PosTerminal::query()
        ->when($hasIsActive || $hasStatusId || $hasStatus, function ($query) use ($hasIsActive, $hasStatusId, $hasStatus) {
            $query->where(function ($q) use ($hasIsActive, $hasStatusId, $hasStatus) {
                if ($hasIsActive) {
                    $q->where('is_active', true);
                }
                if ($hasStatusId) {
                    // Assuming 1 == active in status_id
                    $q->orWhere('status_id', 1);
                }
                if ($hasStatus) {
                    // Some schemas may use a textual status column
                    $q->orWhere('status', 'active');
                }
            });
        })
        ->orderBy('id')
        ->chunk(500, function($chunk) use ($now, $dedupeTtl, $defaultIdle, $multiplier, &$scanned, &$idleNew, &$recovered, &$errors, $perTenantEnabled, &$tenantAgg) {
            foreach ($chunk as $terminal) {
                try {
                    $scanned++;
                    // Determine idle threshold
                    $hb = (int) ($terminal->heartbeat_threshold ?? 300);
                    $idleAfter = max($defaultIdle, $hb * $multiplier);

                    // Determine last activity
                    $lastSeen = $terminal->last_seen_at ?? $terminal->registered_at ?? $terminal->created_at;
                    if (!$lastSeen) { continue; }

                    $idleSeconds = $now->diffInSeconds($lastSeen);
                    $isIdle = $idleSeconds >= $idleAfter;

                    $cacheKeyIdle = sprintf('terminal:idle:%s', $terminal->id);
                    $wasIdle = Cache::get($cacheKeyIdle, false) ? true : false;

                    // Initialize per-tenant bucket if needed
                    if ($perTenantEnabled) {
                        $tid = $terminal->tenant_id ?? 'unassigned';
                        if (!isset($tenantAgg[$tid])) {
                            $tenantAgg[$tid] = [
                                'scanned' => 0,
                                'idle_detected' => 0,
                                'recovered' => 0,
                            ];
                        }
                        $tenantAgg[$tid]['scanned']++;
                    }

                    if ($isIdle && !$wasIdle) {
                        // Mark as idle and log
                        Cache::put($cacheKeyIdle, 1, $dedupeTtl);
                        \App\Models\SystemLog::create([
                            'type' => 'terminal_heartbeat',
                            'log_type' => 'TERMINAL_IDLE_DETECTED',
                            'severity' => 'warning',
                            'terminal_uid' => $terminal->serial_number ?? $terminal->id,
                            'transaction_id' => null,
                            'message' => 'Terminal idle detected',
                            'context' => json_encode([
                                'terminal_id' => $terminal->id,
                                'tenant_id' => $terminal->tenant_id ?? null,
                                'serial_number' => $terminal->serial_number ?? null,
                                'last_seen_at' => optional($terminal->last_seen_at)->toIso8601String(),
                                'idle_seconds' => $idleSeconds,
                                'idle_after_seconds' => $idleAfter,
                                'heartbeat_threshold' => $terminal->heartbeat_threshold ?? null,
                                'scan_time' => $now->toIso8601String(),
                            ])
                        ]);
                        $idleNew++;
                        if ($includeChanged && count($changedTerminals) < $terminalsCap) {
                            $changedTerminals[] = [
                                'event' => 'idle_detected',
                                'terminal_id' => $terminal->id,
                                'tenant_id' => $terminal->tenant_id ?? null,
                                'serial' => $terminal->serial_number ?? null,
                                'ip' => $hasIpColumn ? ($terminal->ip_address ?? null) : null,
                            ];
                        }
                        if ($perTenantEnabled) {
                            $tid = $terminal->tenant_id ?? 'unassigned';
                            $tenantAgg[$tid]['idle_detected']++;
                        }
                    }

                    if (!$isIdle && $wasIdle) {
                        // Recovery detected; clear idle and log once
                        Cache::forget($cacheKeyIdle);
                        \App\Models\SystemLog::create([
                            'type' => 'terminal_heartbeat',
                            'log_type' => 'TERMINAL_RECOVERED',
                            'severity' => 'info',
                            'terminal_uid' => $terminal->serial_number ?? $terminal->id,
                            'transaction_id' => null,
                            'message' => 'Terminal recovered from idle',
                            'context' => json_encode([
                                'terminal_id' => $terminal->id,
                                'tenant_id' => $terminal->tenant_id ?? null,
                                'serial_number' => $terminal->serial_number ?? null,
                                'last_seen_at' => optional($terminal->last_seen_at)->toIso8601String(),
                                'idle_seconds' => $idleSeconds,
                                'idle_after_seconds' => $idleAfter,
                                'heartbeat_threshold' => $terminal->heartbeat_threshold ?? null,
                                'scan_time' => $now->toIso8601String(),
                            ])
                        ]);
                        $recovered++;
                        if ($includeChanged && count($changedTerminals) < $terminalsCap) {
                            $changedTerminals[] = [
                                'event' => 'recovered',
                                'terminal_id' => $terminal->id,
                                'tenant_id' => $terminal->tenant_id ?? null,
                                'serial' => $terminal->serial_number ?? null,
                                'ip' => $hasIpColumn ? ($terminal->ip_address ?? null) : null,
                            ];
                        }
                        if ($perTenantEnabled) {
                            $tid = $terminal->tenant_id ?? 'unassigned';
                            $tenantAgg[$tid]['recovered']++;
                        }
                    }

                } catch (\Throwable $e) {
                    Log::error('[IdleMonitor] Error processing terminal', [
                        'terminal_id' => $terminal->id ?? null,
                        'error' => $e->getMessage(),
                    ]);
                    $errors++;
                }
            }
        });

    // Run summary to ensure visibility in dashboard logs
    try {
        \App\Models\SystemLog::create([
            'type' => 'terminal_heartbeat',
            'log_type' => 'IDLE_MONITOR_SUMMARY',
            'severity' => ($idleNew > 0 || $errors > 0) ? 'info' : 'debug',
            'terminal_uid' => 'scheduler',
            'transaction_id' => null,
            'message' => 'Idle monitor run summary',
            'context' => json_encode([
                'scan_time' => $now->toIso8601String(),
                'scan_interval_minutes' => $scanInterval,
                'scanned' => $scanned,
                'idle_detected' => $idleNew,
                'recovered' => $recovered,
                'errors' => $errors,
                'changed_terminals' => $includeChanged ? $changedTerminals : [],
            ])
        ]);

        // Mirror summary into AuditLog for dashboard visibility
        try {
            \App\Models\AuditLog::create([
                'user_id' => null,
                'ip_address' => null,
                'action' => 'IDLE_MONITOR_SUMMARY',
                'action_type' => 'IDLE_MONITOR_SUMMARY',
                'resource_type' => 'terminal_heartbeat_monitor',
                'resource_id' => 'scheduler',
                'auditable_type' => 'system',
                'auditable_id' => null,
                'message' => 'Idle monitor run summary',
                'metadata' => [
                    'scan_time' => $now->toIso8601String(),
                    'scan_interval_minutes' => $scanInterval,
                    'scanned' => $scanned,
                    'idle_detected' => $idleNew,
                    'recovered' => $recovered,
                    'errors' => $errors,
                    'changed_terminals' => $includeChanged ? $changedTerminals : [],
                ],
            ]);

            // Optional lightweight per-tenant summaries
            if ($perTenantEnabled && is_array($tenantAgg)) {
                foreach ($tenantAgg as $tenantId => $agg) {
                    if ($perTenantOnlyNonzero && ($agg['idle_detected'] === 0 && $agg['recovered'] === 0)) {
                        continue; // keep concise
                    }
                    \App\Models\AuditLog::create([
                        'user_id' => null,
                        'ip_address' => null,
                        'action' => 'IDLE_MONITOR_TENANT_SUMMARY',
                        'action_type' => 'IDLE_MONITOR_TENANT_SUMMARY',
                        'resource_type' => 'tenant',
                        'resource_id' => (string) $tenantId,
                        'auditable_type' => 'tenant',
                        'auditable_id' => is_numeric($tenantId) ? (int) $tenantId : null,
                        'message' => 'Idle monitor tenant summary',
                        'metadata' => [
                            'tenant_id' => $tenantId,
                            'scan_time' => $now->toIso8601String(),
                            'scan_interval_minutes' => $scanInterval,
                            'scanned' => $agg['scanned'] ?? 0,
                            'idle_detected' => $agg['idle_detected'] ?? 0,
                            'recovered' => $agg['recovered'] ?? 0,
                        ],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('[IdleMonitor] Failed to write audit summary', ['error' => $e->getMessage()]);
        }
    } catch (\Throwable $e) {
        Log::error('[IdleMonitor] Failed to write summary log', ['error' => $e->getMessage()]);
    }
})->name('terminals-idle-monitor')
  ->withoutOverlapping()
  ->onOneServer()
  ->when(fn () => (bool) (config('tsms.terminals.idle_monitor.enabled') ?? false))
  ->everyMinute(); // cadence governed by env via modulo; default every 5m

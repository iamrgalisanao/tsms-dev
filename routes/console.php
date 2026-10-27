<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
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
// Intake reconciliation: recover accepted intake records that were not queued.
// --------------------------------------------------------------------------
Schedule::command('tsms:reconcile-intake')
    ->everyMinute()
    ->name('tsms-reconcile-intake')
    ->withoutOverlapping()
    ->onOneServer();

// --------------------------------------------------------------------------
// Processed intake repair: recreate transaction rows missing after intake.
// Runs at a non-peak hour and uses the same command exposed to manual repair.
// --------------------------------------------------------------------------
Schedule::command('tsms:reconcile-intake --repair-missing')
    ->dailyAt('23:00')
    ->name('tsms-repair-missing-transactions')
    ->withoutOverlapping()
    ->onOneServer();

// Keep derived daily report summaries warm for dashboards/reports. This is
// intentionally incremental; historical ranges should be refreshed manually.
// The overlap lock expires after 60 minutes (the 24h default left a killed run
// blocking this task for a day). Running in the background keeps a long refresh
// from delaying the sequential scheduler (watchdog, rejection monitor, prune).
Schedule::command('reports:refresh-daily-transaction-summaries --days=2')
    ->everyFifteenMinutes()
    ->name('reports-refresh-daily-transaction-summaries')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->onOneServer();

// --------------------------------------------------------------------------
// Transaction prune guard (ADR-004 slice 2). Runs hourly and NEVER deletes:
//  - PENDING rows are never deletable (they are unvalidated sales);
//  - hard deletion sits behind tsms.transactions.prune_delete_enabled
//    (TX_PRUNE_DELETE_ENABLED, default false) and, even when enabled, this
//    slice deletes nothing: deletable categories require a retention ADR.
// It logs an extended inventory snapshot only. Note: the old `= 'FAILED'`
// filter is not an enum value; through the validation_status index MySQL maps
// it to the '' error value, so the old COUNT could see blank rows that the
// DELETE (different access path) never matched.
// --------------------------------------------------------------------------
Schedule::call(function () {
    $cfg = config('tsms.transactions');
    if (! ($cfg['enable_pruning'] ?? true)) {
        return; // disabled
    }

    if ($cfg['prune_delete_enabled'] ?? false) {
        // Intentionally no delete here: categories and retention need their own ADR.
        Log::warning('[Prune] TX_PRUNE_DELETE_ENABLED is set but transaction deletion is not implemented (retention ADR required); nothing deleted');
    }

    // One grouped count plus one small PENDING+job FAILED count, each capped at 10s on
    // MySQL so a slow inventory never blocks or fails the scheduler.
    $hint = \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'mysql'
        ? '/*+ MAX_EXECUTION_TIME(10000) */ ' : '';
    try {
        $byStatus = collect(\Illuminate\Support\Facades\DB::select(
            "SELECT {$hint}validation_status AS status, COUNT(*) AS c FROM transactions GROUP BY validation_status"
        ))->mapWithKeys(fn ($r) => [(string) $r->status => (int) $r->c]);
        $pendingJobFailed = (int) \Illuminate\Support\Facades\DB::selectOne(
            "SELECT {$hint}COUNT(*) AS c FROM transactions WHERE validation_status = 'PENDING' AND job_status = 'FAILED'"
        )->c;
    } catch (\Throwable $e) {
        Log::warning('[Prune] Transaction inventory snapshot failed', ['error' => $e->getMessage()]);

        return;
    }

    $snapshot = [
        'valid' => $byStatus->get('VALID', 0),
        'invalid' => $byStatus->get('INVALID', 0),
        'pending' => $byStatus->get('PENDING', 0),
        'pending_job_failed' => $pendingJobFailed,
        // Legacy rows where an invalid enum write was stored as '' (ADR-004 section 3).
        'legacy_blank' => $byStatus->get('', 0),
        'error' => $byStatus->get('ERROR', 0),
        'deleted' => 0,
        'delete_enabled' => (bool) ($cfg['prune_delete_enabled'] ?? false),
    ];
    // transaction_prune.deleted is never incremented: nothing is deleted.

    Log::info('[Prune] Transaction inventory snapshot', $snapshot);
})->hourly()->name('transactions-prune')->withoutOverlapping()->onOneServer();

// --------------------------------------------------------------------------
// Transaction Watchdog (ADR-004 D7): App\Console\Commands\TransactionWatchdogCommand.
// Re-dispatches stale PENDING+QUEUED rows only when the job's unique lock is free,
// retries PENDING+FAILED rows with backoff up to a cap (then alerts admins), and
// sets job_status=FAILED on PENDING rows past max_pending. Never writes
// validation_status or job_attempts.
// --------------------------------------------------------------------------
Schedule::command('tsms:transactions-watchdog')
    ->everyFiveMinutes()
    ->name('transactions-watchdog')
    ->withoutOverlapping()
    ->onOneServer();

// --------------------------------------------------------------------------
// Tenant inactivity alerts: notify admins/finance when monitored tenants or
// terminals stop sending transactions within configured thresholds.
// --------------------------------------------------------------------------
Schedule::call(function () {
    app(\App\Services\NotificationService::class)->checkTenantInactivity();
})
    ->everyFifteenMinutes()
    ->name('tenant-inactivity-alerts')
    ->withoutOverlapping()
    ->onOneServer();

// --------------------------------------------------------------------------
// Intake rejection monitor (003 US3): alert admins on rejection spikes per
// terminal/code and on terminals whose every recent submission was rejected.
// --------------------------------------------------------------------------
Schedule::call(function () {
    app(\App\Services\Monitoring\IntakeRejectionMonitor::class)->check();
})
    ->everyFifteenMinutes()
    ->name('intake-rejection-monitor')
    ->withoutOverlapping()
    ->onOneServer()
    ->when(fn () => (bool) config('ingestion.rejection_alerts.enabled', true));

// --------------------------------------------------------------------------
// SystemLog pruning: scheduled safe prune (uses systemlogs:prune command)
// Default: keep 90 days of logs; scheduled daily at 02:00. Can be toggled
// via config key `tsms.logs.enable_prune`.
// --------------------------------------------------------------------------
Schedule::command('systemlogs:prune --days=90 --force')
    ->dailyAt('02:00')
    ->name('systemlogs-prune-daily')
    ->onOneServer()
    ->when(fn () => (bool) (config('tsms.logs.enable_prune') ?? true));

// --------------------------------------------------------------------------
// Reporting refresh: keep summary tables warm and consistent
// Runs once daily; can be enabled/disabled via config('tsms.reporting.enabled')
// Adjust --hours for hourly refresh window as desired.
// --------------------------------------------------------------------------
// NOTE: `reporting:refresh` expects the table as a positional argument (table)
// (signature: reporting:refresh {table=transactions_hourly} {--hours=3})
// Previous invocations used a non-existent --table option which causes errors.
Schedule::command('reporting:refresh transactions_hourly --hours=6')
    ->dailyAt('02:30')
    ->name('reporting-refresh-daily')
    ->withoutOverlapping()
    ->onOneServer()
    ->when(fn () => (bool) (config('tsms.reporting.enabled') ?? true));

// Incremental dispatch every 5 minutes: split the last N minutes into chunk jobs
Schedule::command('reporting:dispatch --minutes=15 --chunk=5')
    ->everyFiveMinutes()
    ->name('reporting-dispatch-incremental')
    ->withoutOverlapping()
    ->onOneServer()
    ->when(fn () => (bool) (config('tsms.reporting.enabled') ?? true));

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
    $includeCurrent = (bool) ($summaryCfg['include_currently_idle'] ?? false);
    $currentCap = (int) ($summaryCfg['currently_idle_cap'] ?? 25);
    $hasIpColumn = Schema::hasColumn('pos_terminals', 'ip_address');
    $changedTerminals = [];
    $currentIdleList = [];
    $activityBasis = ($cfg['activity_basis'] ?? 'last_seen'); // last_seen|last_sale|composite
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
        ->chunk(500, function($chunk) use ($now, $dedupeTtl, $defaultIdle, $multiplier, &$scanned, &$idleNew, &$recovered, &$errors, $perTenantEnabled, &$tenantAgg, $hasIpColumn, $includeChanged, $terminalsCap, $includeCurrent, $currentCap, &$currentIdleList, $activityBasis) {
            foreach ($chunk as $terminal) {
                try {
                    $scanned++;
                    // Determine idle threshold
                    $hb = (int) ($terminal->heartbeat_threshold ?? 300);
                    $idleAfter = max($defaultIdle, $hb * $multiplier);

                    // Determine last activity based on configured basis
                    $candidate = null;
                    if ($activityBasis === 'last_sale') {
                        $candidate = $terminal->last_sale_at ?? null;
                    } elseif ($activityBasis === 'composite') {
                        // Use the most recent of sale vs seen; if both null, fallback to registered/created
                        $sale = $terminal->last_sale_at ?? null;
                        $seen = $terminal->last_seen_at ?? null;
                        if ($sale && $seen) {
                            $candidate = $sale->greaterThan($seen) ? $sale : $seen;
                        } else {
                            $candidate = $sale ?: $seen;
                        }
                    } else { // last_seen
                        $candidate = $terminal->last_seen_at ?? null;
                    }
                    $lastActivity = $candidate ?? $terminal->registered_at ?? $terminal->created_at;
                    if (!$lastActivity) { continue; }

                    $idleSeconds = $now->diffInSeconds($lastActivity);
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

                    if ($includeCurrent && $isIdle && count($currentIdleList) < $currentCap) {
                        $currentIdleList[] = [
                            'terminal_id' => $terminal->id,
                            'tenant_id' => $terminal->tenant_id ?? null,
                            'serial' => $terminal->serial_number ?? null,
                            'ip' => $hasIpColumn ? ($terminal->ip_address ?? null) : null,
                            'idle_seconds' => $idleSeconds,
                        ];
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
                                'last_sale_at' => optional($terminal->last_sale_at)->toIso8601String(),
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
                                'last_sale_at' => optional($terminal->last_sale_at)->toIso8601String(),
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
                'currently_idle' => $includeCurrent ? $currentIdleList : [],
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
                    'currently_idle' => $includeCurrent ? $currentIdleList : [],
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

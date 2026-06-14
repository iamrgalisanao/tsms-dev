<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Tenant;
use App\Models\User;
use App\Models\SystemLog;
use App\Models\PosTerminal;
use App\Notifications\TransactionFailureThresholdExceeded;
use App\Notifications\BatchProcessingFailure;
use App\Notifications\SecurityAuditAlert;
use App\Notifications\TenantInactivityAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

class NotificationService
{
    private array $config;

    /**
     * NotificationService constructor.
     *
     * Initializes the notification configuration with default values if not set.
     * Configuration options include:
     * - transaction_failure_threshold: int, number of transaction failures before notification.
     * - transaction_failure_time_window: int, time window in minutes to monitor failures.
     * - batch_failure_threshold: int, minimum batch failures to trigger notification.
     * - notification_channels: array, channels to send notifications (e.g., mail, database).
     * - admin_emails: array, list of admin email addresses to notify.
     */
    public function __construct()
    {
        $this->config = config('notifications', [
            'transaction_failure_threshold' => 10,
            'transaction_failure_time_window' => 60, // minutes
            'batch_failure_threshold' => 5, // minimum failures to notify
            'notification_channels' => ['mail', 'database'],
            'admin_emails' => ['admin@tsms.com'],
        ]);
    }

    /**
     * Check transaction failure thresholds and send notifications if exceeded
     */
    public function checkTransactionFailureThresholds(?string $posTerminalId = null): void
    {
        try {
            $threshold = $this->config['transaction_failure_threshold'];
            $timeWindow = $this->config['transaction_failure_time_window'];
            $cutoffTime = Carbon::now()->subMinutes($timeWindow);

            $query = Transaction::where('created_at', '>=', $cutoffTime)
                ->where(function ($q) {
                    $q->where('validation_status', 'INVALID');
                });

            if ($posTerminalId) {
                $query->where('terminal_id', $posTerminalId);
            }

            $failures = $query->orderBy('created_at', 'desc')->get();
            $failureCount = $failures->count();

            Log::info('Transaction failure threshold check', [
                'pos_terminal_id' => $posTerminalId,
                'threshold' => $threshold,
                'current_count' => $failureCount,
                'time_window_minutes' => $timeWindow,
                'cutoff_time' => $cutoffTime,
                'failures_found' => $failures->pluck('id')->toArray(),
                'query_sql' => $query->toSql(),
                'query_bindings' => $query->getBindings(),
            ]);

            if ($failureCount >= $threshold) {
                Log::info('Threshold exceeded, sending notification');
                
                // Transform failures into expected format
                $formattedFailures = $failures->take(10)->map(function ($transaction) {
                    return [
                        'transaction_id' => $transaction->transaction_id,
                        'error_message' => 'Validation failed - Status: ' . $transaction->validation_status,
                        'failed_at' => $transaction->created_at->format('Y-m-d H:i:s'),
                    ];
                })->toArray();
                
                // Per-terminal cooldown to prevent alert storms
                $cooldownMinutes = (int) (config('notifications.rate_limiting.cooldown_minutes', 15));
                $key = sprintf('alerts:tx-failure-threshold:%s', $posTerminalId ?? 'global');
                $allowed = RateLimiter::attempt($key, 1, function () { return true; }, $cooldownMinutes * 60);

                if (!$allowed) {
                    Log::info('Alert suppressed due to cooldown', [
                        'key' => $key,
                        'cooldown_minutes' => $cooldownMinutes,
                    ]);
                } else {
                    $this->sendTransactionFailureNotification($posTerminalId, $failureCount, $formattedFailures);
                }
                
                Log::warning('Transaction failure threshold exceeded', [
                    'pos_terminal_id' => $posTerminalId,
                    'threshold' => $threshold,
                    'current_count' => $failureCount,
                    'time_window_minutes' => $timeWindow,
                ]);
            } else {
                Log::info('Threshold not exceeded, no notification sent');
            }
        } catch (\Exception $e) {
            Log::error('Failed to check transaction failure thresholds', [
                'error' => $e->getMessage(),
                'pos_terminal_id' => $posTerminalId,
            ]);
        }
    }

    /**
     * Send notification for transaction failure threshold exceeded
     */
    private function sendTransactionFailureNotification(?string $posTerminalId, int $failureCount, array $recentFailures): void
    {
        $thresholdData = [
            'threshold' => $this->config['transaction_failure_threshold'],
            'current_count' => $failureCount,
            'time_window_minutes' => $this->config['transaction_failure_time_window'],
            'pos_terminal_id' => $posTerminalId,
        ];

        $notification = new TransactionFailureThresholdExceeded($thresholdData, $recentFailures);
        $this->sendToAdmins($notification);
    }

    /**
     * Send notification for batch processing failures
     */
    public function notifyBatchProcessingFailure(string $batchId, int $totalTransactions, array $failedTransactions): void
    {
        try {
            $failedCount = count($failedTransactions);
            
            // Only notify if failures exceed threshold
            if ($failedCount >= $this->config['batch_failure_threshold']) {
                $batchData = [
                    'batch_id' => $batchId,
                    'total_transactions' => $totalTransactions,
                    'failed_count' => $failedCount,
                    'success_count' => $totalTransactions - $failedCount,
                ];

                $notification = new BatchProcessingFailure($batchData, $failedTransactions);
                $this->sendToAdmins($notification);

                Log::warning('Batch processing failure notification sent', [
                    'batch_id' => $batchId,
                    'failed_count' => $failedCount,
                    'total_transactions' => $totalTransactions,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send batch processing failure notification', [
                'error' => $e->getMessage(),
                'batch_id' => $batchId,
            ]);
        }
    }

    /**
     * Send security audit alert notification
     */
    public function sendSecurityAuditAlert(string $alertType, array $auditData): void
    {
        try {
            $notification = new SecurityAuditAlert($alertType, $auditData);
            $this->sendToAdmins($notification);

            Log::warning('Security audit alert sent', [
                'alert_type' => $alertType,
                'audit_data' => $auditData,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send security audit alert', [
                'error' => $e->getMessage(),
                'alert_type' => $alertType,
            ]);
        }
    }

    /**
     * Send notification to admin users
     */
    private function sendToAdmins($notification): void
    {
        // Send to admin users via database
        $adminUsers = User::whereHas('roles', function ($query) {
            $query->where('name', 'admin');
        })->get();

        // In testing, if no admin users exist, send to any user for testing purposes
        if ($adminUsers->isEmpty() && app()->environment('testing')) {
            $adminUsers = User::limit(1)->get();
        }

        if ($adminUsers->isNotEmpty()) {
            Notification::send($adminUsers, $notification);
        }

        // Send to configured admin emails
        if (!empty($this->config['admin_emails'])) {
            Notification::route('mail', $this->config['admin_emails'])
                ->notify($notification);
        }
    }

    /**
     * Send notification to admin and finance users.
     */
    private function sendToAdminsAndFinance($notification): void
    {
        $users = User::whereHas('roles', function ($query) {
            $query->whereIn('name', ['admin', 'finance']);
        })->get();

        if ($users->isEmpty() && app()->environment('testing')) {
            $users = User::limit(1)->get();
        }

        if ($users->isNotEmpty()) {
            Notification::send($users, $notification);
        }
    }

    /**
     * Get recent notifications for dashboard
     */
    public function getRecentNotifications(int $limit = 10): array
    {
        try {
            return DB::table('notifications')
                ->whereNull('read_at')
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Failed to get recent notifications', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(string $notificationId): bool
    {
        try {
            $updated = DB::table('notifications')
                ->where('id', $notificationId)
                ->update(['read_at' => now()]);

            return $updated > 0;
        } catch (\Exception $e) {
            Log::error('Failed to mark notification as read', [
                'error' => $e->getMessage(),
                'notification_id' => $notificationId,
            ]);
            return false;
        }
    }

    /**
     * Get notification statistics
     */
    public function getNotificationStats(): array
    {
        try {
            $stats = DB::table('notifications')
                ->select(
                    DB::raw('COUNT(*) as total'),
                    DB::raw('COUNT(CASE WHEN read_at IS NULL THEN 1 END) as unread'),
                    DB::raw('COUNT(CASE WHEN read_at IS NOT NULL THEN 1 END) as read')
                )
                ->where('created_at', '>=', Carbon::now()->subDays(30))
                ->first();

            return [
                'total' => $stats->total ?? 0,
                'unread' => $stats->unread ?? 0,
                'read' => $stats->read ?? 0,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get notification stats', [
                'error' => $e->getMessage(),
            ]);
            return ['total' => 0, 'unread' => 0, 'read' => 0];
        }
    }

    /**
     * Detect monitored tenants/terminals with no recent transactions and send a consolidated alert.
     */
    public function checkTenantInactivity(): void
    {
        try {
            if (! ($this->config['tenant_inactivity_enabled'] ?? true)) {
                return;
            }

            $defaultThresholdMinutes = (int) ($this->config['tenant_inactivity_threshold_minutes'] ?? 60);
            $cooldownMinutes = (int) ($this->config['tenant_inactivity_cooldown_minutes'] ?? 60);
            $now = Carbon::now();
            $hasTenantMonitoringColumns = $this->hasActivityMonitoringColumns('tenants');
            $hasTerminalMonitoringColumns = $this->hasActivityMonitoringColumns('pos_terminals');
            $hasTenantSuppressionColumns = $this->hasActivitySuppressionColumns('tenants');
            $hasTerminalSuppressionColumns = $this->hasActivitySuppressionColumns('pos_terminals');

            $tenantColumns = $this->existingColumns('tenants', [
                'id',
                'trade_name',
                'customer_code',
                'status',
                'activity_monitoring_enabled',
                'activity_threshold_minutes',
                'activity_suppressed_until',
                'activity_suppression_reason',
            ]);

            $terminalColumns = $this->existingColumns('pos_terminals', [
                'id',
                'tenant_id',
                'serial_number',
                'machine_number',
                'is_active',
                'status_id',
                'expires_at',
                'activity_monitoring_enabled',
                'activity_threshold_minutes',
                'activity_suppressed_until',
                'activity_suppression_reason',
            ]);

            $activeTenants = Tenant::query()
                ->with(['posTerminals' => fn ($query) => $query->select($terminalColumns)])
                ->whereHas('posTerminals', function ($query) {
                    $query->where('is_active', true)
                        ->where('status_id', 1)
                        ->where(function ($inner) {
                            $inner->whereNull('expires_at')
                                ->orWhere('expires_at', '>', Carbon::now());
                        });
                })
                ->when($hasTenantMonitoringColumns, fn ($query) => $query->where('activity_monitoring_enabled', true))
                ->where(function ($query) {
                    $query->whereNull('status')
                        ->orWhereRaw('LOWER(status) = ?', ['operational']);
                })
                ->get($tenantColumns);

            if ($activeTenants->isEmpty()) {
                Log::info('Tenant inactivity check: no active tenants found');

                return;
            }

            $activeTenantIds = $activeTenants->pluck('id');
            $silentTenantIds = collect();
            $notifiableTenants = [];
            $notifiableTerminals = [];
            $suppressedTenants = [];
            $suppressedTerminals = [];

            foreach ($activeTenants as $tenant) {
                $tenantThreshold = (int) (($hasTenantMonitoringColumns ? $tenant->activity_threshold_minutes : null) ?: $defaultThresholdMinutes);
                $tenantCutoff = $now->copy()->subMinutes($tenantThreshold);
                $tenantSuppressed = $hasTenantSuppressionColumns && $this->isAlertSuppressed($tenant->activity_suppressed_until ?? null, $now);

                if ($tenantSuppressed) {
                    $suppressedTenants[] = $tenant->id;
                }

                $lastTenantTransactionAt = $this->lastTransactionTimestamp($tenant->id);
                $tenantIsSilent = ! $lastTenantTransactionAt || $lastTenantTransactionAt->lt($tenantCutoff);

                if ($tenantIsSilent) {
                    $silentTenantIds->push($tenant->id);
                }

                $activeTerminals = $tenant->posTerminals
                    ->filter(fn (PosTerminal $terminal) => $terminal->isActiveAndValid())
                    ->filter(fn (PosTerminal $terminal) => ! $hasTerminalMonitoringColumns || ($terminal->activity_monitoring_enabled ?? true));

                foreach ($activeTerminals as $terminal) {
                    $terminalThreshold = (int) (($hasTerminalMonitoringColumns ? $terminal->activity_threshold_minutes : null) ?: $tenantThreshold);
                    $terminalCutoff = $now->copy()->subMinutes($terminalThreshold);
                    $terminalSuppressed = $tenantSuppressed
                        || ($hasTerminalSuppressionColumns && $this->isAlertSuppressed($terminal->activity_suppressed_until ?? null, $now));

                    if ($terminalSuppressed) {
                        $suppressedTerminals[] = $terminal->id;
                        continue;
                    }

                    $lastTerminalTransactionAt = $this->lastTransactionTimestamp($tenant->id, $terminal->id);
                    if ($lastTerminalTransactionAt && $lastTerminalTransactionAt->gte($terminalCutoff)) {
                        continue;
                    }

                    $terminalRateKey = sprintf('alerts:terminal-inactivity:%d', $terminal->id);
                    if (! RateLimiter::attempt($terminalRateKey, 1, fn () => true, $cooldownMinutes * 60)) {
                        Log::info('Terminal inactivity alert suppressed due to cooldown', [
                            'tenant_id' => $tenant->id,
                            'terminal_id' => $terminal->id,
                            'cooldown_minutes' => $cooldownMinutes,
                        ]);
                        continue;
                    }

                    $terminalAlert = [
                        'tenant_id' => $tenant->id,
                        'tenant_name' => $tenant->trade_name,
                        'customer_code' => $tenant->customer_code,
                        'terminal_id' => $terminal->id,
                        'serial_number' => $terminal->serial_number,
                        'machine_number' => $terminal->machine_number,
                        'inactive_minutes' => $terminalThreshold,
                        'last_transaction_at' => $lastTerminalTransactionAt?->toDateTimeString(),
                    ];
                    $notifiableTerminals[] = $terminalAlert;
                    $this->writeInactivitySystemLog('TERMINAL_INACTIVITY_ALERT', "Terminal inactivity detected: {$tenant->trade_name} / {$terminal->serial_number}", $terminal->serial_number ?? (string) $terminal->id, $terminalAlert);
                }

                if (! $tenantIsSilent || $tenantSuppressed) {
                    continue;
                }

                $tenantRateKey = sprintf('alerts:tenant-inactivity:%d', $tenant->id);
                if (! RateLimiter::attempt($tenantRateKey, 1, fn () => true, $cooldownMinutes * 60)) {
                    Log::info('Tenant inactivity alert suppressed due to cooldown', [
                        'tenant_id' => $tenant->id,
                        'cooldown_minutes' => $cooldownMinutes,
                    ]);
                    continue;
                }

                $tenantAlert = [
                    'tenant_id' => $tenant->id,
                    'name' => $tenant->trade_name,
                    'customer_code' => $tenant->customer_code,
                    'inactive_minutes' => $tenantThreshold,
                    'last_transaction_at' => $lastTenantTransactionAt?->toDateTimeString(),
                    'active_terminal_count' => $activeTerminals->count(),
                ];
                $notifiableTenants[] = $tenantAlert;
                $this->writeInactivitySystemLog('TENANT_INACTIVITY_ALERT', "Tenant inactivity detected: {$tenant->trade_name}", 'scheduler', $tenantAlert);
            }

            Log::info('Tenant inactivity check', [
                'default_threshold_minutes' => $defaultThresholdMinutes,
                'active_tenants' => $activeTenantIds->values(),
                'silent_tenants' => $silentTenantIds->values(),
                'notifiable_tenants' => collect($notifiableTenants)->pluck('tenant_id')->values(),
                'notifiable_terminals' => collect($notifiableTerminals)->pluck('terminal_id')->values(),
                'suppressed_tenants' => $suppressedTenants,
                'suppressed_terminals' => $suppressedTerminals,
            ]);

            $this->writeInactivitySystemLog('TENANT_INACTIVITY_SUMMARY', 'Tenant inactivity check summary', 'scheduler', [
                'threshold_minutes' => $defaultThresholdMinutes,
                'checked_at' => $now->toIso8601String(),
                'active_tenants' => $activeTenantIds->values(),
                'silent_tenants' => $silentTenantIds->values(),
                'notifiable_tenants' => collect($notifiableTenants)->pluck('tenant_id')->values(),
                'notifiable_terminals' => collect($notifiableTerminals)->pluck('terminal_id')->values(),
                'suppressed_tenants' => $suppressedTenants,
                'suppressed_terminals' => $suppressedTerminals,
            ], $silentTenantIds->isEmpty() ? 'info' : 'warning');

            if (empty($notifiableTenants) && empty($notifiableTerminals)) {
                return;
            }

            $notification = new TenantInactivityAlert($notifiableTenants, $notifiableTerminals);
            $this->sendToAdminsAndFinance($notification);

            $helpdeskEmails = $this->config['tenant_inactivity_emails'] ?? [];
            if (! empty($helpdeskEmails)) {
                Notification::route('mail', $helpdeskEmails)->notify($notification);
            }
        } catch (\Exception $e) {
            Log::error('Failed to check tenant inactivity', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function lastTransactionTimestamp(int $tenantId, ?int $terminalId = null): ?Carbon
    {
        $value = Transaction::query()
            ->where('tenant_id', $tenantId)
            ->when($terminalId, fn ($query) => $query->where('terminal_id', $terminalId))
            ->max('transaction_timestamp');

        return $value ? Carbon::parse($value) : null;
    }

    private function isAlertSuppressed($suppressedUntil, Carbon $now): bool
    {
        return $suppressedUntil && Carbon::parse($suppressedUntil)->greaterThan($now);
    }

    private function existingColumns(string $table, array $columns): array
    {
        return array_values(array_filter(
            $columns,
            fn (string $column) => Schema::hasColumn($table, $column)
        ));
    }

    private function hasActivityMonitoringColumns(string $table): bool
    {
        return Schema::hasColumn($table, 'activity_monitoring_enabled')
            && Schema::hasColumn($table, 'activity_threshold_minutes');
    }

    private function hasActivitySuppressionColumns(string $table): bool
    {
        return Schema::hasColumn($table, 'activity_suppressed_until')
            && Schema::hasColumn($table, 'activity_suppression_reason');
    }

    private function writeInactivitySystemLog(string $logType, string $message, string $terminalUid, array $context, string $severity = 'warning'): void
    {
        try {
            SystemLog::create([
                'type' => 'tenant_inactivity',
                'log_type' => $logType,
                'severity' => $severity,
                'terminal_uid' => $terminalUid,
                'transaction_id' => null,
                'message' => $message,
                'context' => array_merge($context, ['source' => 'batch']),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to write tenant inactivity SystemLog', [
                'log_type' => $logType,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

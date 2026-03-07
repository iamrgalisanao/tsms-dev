<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Tenant;
use App\Models\User;
use App\Models\SystemLog;
use App\Notifications\TransactionFailureThresholdExceeded;
use App\Notifications\BatchProcessingFailure;
use App\Notifications\SecurityAuditAlert;
use App\Notifications\TenantInactivityAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

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
        $roles = ['admin', 'finance'];

        $users = User::whereHas('roles', function ($query) use ($roles) {
            $query->whereIn('name', $roles);
        })->get();

        if ($users->isEmpty() && app()->environment('testing')) {
            $users = User::limit(1)->get();
        }

        if ($users->isNotEmpty()) {
            Notification::send($users, $notification);
        }
    }

    /**
     * Get recent notifications for dashboards.
     * When a user ID is provided, results are scoped to that user.
     */
    public function getRecentNotifications(int $limit = 10, ?int $userId = null): array
    {
        try {
            $query = DB::table('notifications')
                ->whereNull('read_at')
                ->orderBy('created_at', 'desc')
                ->limit($limit);

            if ($userId !== null) {
                $query->where('notifiable_id', $userId)
                    ->where('notifiable_type', '\\App\\Models\\User');
            }

            return $query->get()->toArray();
        } catch (\Exception $e) {
            Log::error('Failed to get recent notifications', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
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
     * Detect tenants that have not sent any transactions within the
     * configured inactivity window and send alerts to admin and finance.
     */
    public function checkTenantInactivity(): void
    {
        try {
            if (!($this->config['tenant_inactivity_enabled'] ?? true)) {
                return;
            }

            $thresholdMinutes = (int) ($this->config['tenant_inactivity_threshold_minutes'] ?? 60);
            $cooldownMinutes = (int) ($this->config['tenant_inactivity_cooldown_minutes'] ?? 60);
            $cutoffTime = Carbon::now()->subMinutes($thresholdMinutes);

            // Tenants with at least one active & valid POS terminal
            $activeTenantIds = Tenant::whereHas('posTerminals', function ($q) {
                $q->where('is_active', true)
                    ->where('status_id', 1)
                    ->where(function ($q2) {
                        $q2->whereNull('expires_at')
                            ->orWhere('expires_at', '>', Carbon::now());
                    });
            })->pluck('id');

            if ($activeTenantIds->isEmpty()) {
                Log::info('Tenant inactivity check: no active tenants found');
                return;
            }

            // Tenants that have activity within the window
            $recentTenantIds = Transaction::whereIn('tenant_id', $activeTenantIds)
                ->where('created_at', '>=', $cutoffTime)
                ->distinct()
                ->pluck('tenant_id');

            $silentTenantIds = $activeTenantIds->diff($recentTenantIds);

            Log::info('Tenant inactivity check', [
                'threshold_minutes' => $thresholdMinutes,
                'cutoff_time' => $cutoffTime,
                'active_tenants' => $activeTenantIds->values(),
                'recent_tenants' => $recentTenantIds->values(),
                'silent_tenants' => $silentTenantIds->values(),
            ]);

            // Mirror summary into SystemLog so it appears in System Telemetry Archive
            try {
                SystemLog::create([
                    'type' => 'tenant_inactivity',
                    'log_type' => 'TENANT_INACTIVITY_SUMMARY',
                    'severity' => $silentTenantIds->isEmpty() ? 'info' : 'warning',
                    'terminal_uid' => 'scheduler',
                    'transaction_id' => null,
                    'message' => 'Tenant inactivity check summary',
                    'context' => [
                        'threshold_minutes' => $thresholdMinutes,
                        'cutoff_time' => $cutoffTime->toIso8601String(),
                        'active_tenants' => $activeTenantIds->values(),
                        'recent_tenants' => $recentTenantIds->values(),
                        'silent_tenants' => $silentTenantIds->values(),
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to write tenant inactivity SystemLog summary', [
                    'error' => $e->getMessage(),
                ]);
            }

            if ($silentTenantIds->isEmpty()) {
                return;
            }

            $silentTenants = Tenant::with('posTerminals')
                ->whereIn('id', $silentTenantIds)
                ->get();

            foreach ($silentTenants as $tenant) {
                $rateKey = sprintf('alerts:tenant-inactivity:%d', $tenant->id);

                $allowed = RateLimiter::attempt($rateKey, 1, function () {
                    return true;
                }, $cooldownMinutes * 60);

                if (!$allowed) {
                    Log::info('Tenant inactivity alert suppressed due to cooldown', [
                        'tenant_id' => $tenant->id,
                        'rate_key' => $rateKey,
                        'cooldown_minutes' => $cooldownMinutes,
                    ]);
                    continue;
                }

                $lastTxn = Transaction::where('tenant_id', $tenant->id)
                    ->orderByDesc('created_at')
                    ->first();

                $activeTerminals = $tenant->posTerminals
                    ->filter(function ($terminal) {
                        return $terminal->isActiveAndValid();
                    });

                $data = [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->trade_name,
                    'inactive_minutes' => $thresholdMinutes,
                    'last_transaction_at' => $lastTxn?->created_at?->toDateTimeString(),
                    'active_terminal_count' => $activeTerminals->count(),
                ];

                $notification = new TenantInactivityAlert($data);
                $this->sendToAdminsAndFinance($notification);

                Log::warning('Tenant inactivity alert sent', $data);

                // Log alert into SystemLog for telemetry visibility
                try {
                    SystemLog::create([
                        'type' => 'tenant_inactivity',
                        'log_type' => 'TENANT_INACTIVITY_ALERT',
                        'severity' => 'warning',
                        'terminal_uid' => 'scheduler',
                        'transaction_id' => null,
                        'message' => 'Tenant inactivity alert dispatched',
                        'context' => $data,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Failed to write tenant inactivity alert SystemLog', [
                        'tenant_id' => $tenant->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to check tenant inactivity', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

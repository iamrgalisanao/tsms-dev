<?php

namespace App\Services\Monitoring;

use App\Models\SystemLog;
use App\Notifications\IntakeRejectionAlert;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * US3 (FR-009..FR-011, research R5 + A8): scheduled scan of
 * transaction_intake for rejection spikes and for terminals whose every
 * submission in the look-back window was rejected. Reads transaction_intake
 * only (never transactions), alerts admins through NotificationService with
 * a per (type, terminal, code) cooldown, and never throws to the scheduler.
 */
class IntakeRejectionMonitor
{
    private const MYSQL_MAX_EXECUTION_MS = 5000;

    public function __construct(private NotificationService $notifications) {}

    public function check(): void
    {
        if (! (bool) config('ingestion.rejection_alerts.enabled', true)) {
            return;
        }

        $this->guarded('rejection_spike', fn () => $this->checkRejectionSpikes());
        $this->guarded('no_accepted_sales', fn () => $this->checkNoAcceptedSales());
    }

    private function checkRejectionSpikes(): void
    {
        $windowMinutes = max(1, (int) config('ingestion.rejection_alerts.window_minutes', 60));
        $threshold = max(1, (int) config('ingestion.rejection_alerts.threshold', 100));

        $rows = $this->query(
            'SELECT '.$this->hint().'tenant_id, terminal_id, last_error_code, COUNT(*) AS rejected_count
               FROM transaction_intake
              WHERE intake_status = ? AND received_at >= ?
              GROUP BY tenant_id, terminal_id, last_error_code
             HAVING COUNT(*) >= ?',
            ['REJECTED', now()->subMinutes($windowMinutes)->toDateTimeString(), $threshold]
        );

        foreach ($rows as $row) {
            $this->alert(new IntakeRejectionAlert(
                IntakeRejectionAlert::TYPE_REJECTION_SPIKE,
                (int) $row->tenant_id,
                (int) $row->terminal_id,
                $row->last_error_code,
                (int) $row->rejected_count,
                $windowMinutes,
            ));
        }
    }

    private function checkNoAcceptedSales(): void
    {
        $hours = max(1, (int) config('ingestion.rejection_alerts.no_accept_hours', 24));

        // A8: one range scan on received_at; "accepted" means any intake row
        // that was not rejected at intake, regardless of processing outcome.
        $rows = $this->query(
            'SELECT '.$this->hint().'tenant_id, terminal_id,
                    SUM(CASE WHEN intake_status = ? THEN 1 ELSE 0 END) AS rejected_count,
                    SUM(CASE WHEN intake_status <> ? THEN 1 ELSE 0 END) AS accepted_count
               FROM transaction_intake
              WHERE received_at >= ?
              GROUP BY tenant_id, terminal_id',
            ['REJECTED', 'REJECTED', now()->subHours($hours)->toDateTimeString()]
        );

        foreach ($rows as $row) {
            if ((int) $row->rejected_count > 0 && (int) $row->accepted_count === 0) {
                $this->alert(new IntakeRejectionAlert(
                    IntakeRejectionAlert::TYPE_NO_ACCEPTED_SALES,
                    (int) $row->tenant_id,
                    (int) $row->terminal_id,
                    null,
                    (int) $row->rejected_count,
                    $hours * 60,
                ));
            }
        }
    }

    private function alert(IntakeRejectionAlert $alert): void
    {
        $code = $alert->errorCode ?? 'ALL';
        $key = "intake-rejection-alert:{$alert->alertType}:{$alert->terminalId}:{$code}";
        $cooldownSeconds = max(1, (int) config('ingestion.rejection_alerts.cooldown_minutes', 360)) * 60;

        $refs = [
            'alert_type' => $alert->alertType,
            'tenant_id' => $alert->tenantId,
            'terminal_id' => $alert->terminalId,
            'error_code' => $alert->errorCode,
            'count' => $alert->count,
            'window_minutes' => $alert->windowMinutes,
        ];

        // Per-row isolation: a delivery failure is logged and does not skip
        // the remaining rows; the cooldown is spent only after a successful
        // delivery so the alert is retried on the next run.
        try {
            if (RateLimiter::tooManyAttempts($key, 1)) {
                Log::info('Intake rejection alert suppressed due to cooldown', $refs + ['key' => $key]);

                return;
            }

            $this->notifications->notifyAdmins($alert);
            RateLimiter::hit($key, $cooldownSeconds);
        } catch (\Throwable $e) {
            Log::error('Intake rejection alert delivery failed', $refs + [
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->writeSystemLog($alert, $refs);
        Log::warning('Intake rejection alert raised', $refs);
    }

    private function writeSystemLog(IntakeRejectionAlert $alert, array $refs): void
    {
        try {
            SystemLog::create([
                'type' => 'intake_rejection',
                'log_type' => strtoupper($alert->alertType).'_ALERT',
                'severity' => 'warning',
                'terminal_uid' => (string) $alert->terminalId,
                'transaction_id' => null,
                'message' => $alert->alertType === IntakeRejectionAlert::TYPE_NO_ACCEPTED_SALES
                    ? "No accepted sales: terminal {$alert->terminalId}"
                    : "Intake rejection spike: terminal {$alert->terminalId} ({$alert->errorCode})",
                'context' => array_merge($refs, ['source' => 'intake_rejection_monitor']),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to write intake rejection SystemLog', [
                'alert_type' => $alert->alertType,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function guarded(string $check, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::error('IntakeRejectionMonitor check failed', [
                'check' => $check,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function hint(): string
    {
        return DB::connection()->getDriverName() === 'mysql'
            ? '/*+ MAX_EXECUTION_TIME('.self::MYSQL_MAX_EXECUTION_MS.') */ '
            : '';
    }

    /**
     * Seam for tests that need to force a database failure.
     */
    protected function query(string $sql, array $bindings): array
    {
        return DB::select($sql, $bindings);
    }
}

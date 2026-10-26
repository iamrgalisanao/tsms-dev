<?php

namespace App\Services\Monitoring;

use App\Models\SystemLog;
use App\Models\TransactionIntake;
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

    /**
     * Every intake status other than REJECTED; listed explicitly (not `<>`)
     * so the composite index range scan continues onto received_at.
     */
    private const NON_REJECTED_INTAKE_STATUSES = [
        TransactionIntake::INTAKE_STATUS_RECEIVED,
        TransactionIntake::INTAKE_STATUS_ACCEPTED,
        TransactionIntake::INTAKE_STATUS_QUEUED,
    ];

    /**
     * Candidate (tenant, terminal) pairs per accepted-intake lookup query.
     */
    protected int $noAcceptChunkSize = 200;

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
        $minRejections = max(1, (int) config('ingestion.rejection_alerts.no_accept_min_rejections', 10));
        $since = now()->subHours($hours)->toDateTimeString();

        // A8: "accepted" means any intake row that was not rejected at intake,
        // regardless of processing outcome. Two indexed steps instead of one
        // full-window GROUP BY (which exceeded the execution cap on staging):
        // 1) candidate terminals with at least no_accept_min_rejections
        // rejections, via idx_intake_status_received;
        $candidates = $this->query(
            'SELECT '.$this->hint().'tenant_id, terminal_id, COUNT(*) AS rejected_count
               FROM transaction_intake
              WHERE intake_status = ? AND received_at >= ?
              GROUP BY tenant_id, terminal_id
             HAVING COUNT(*) >= ?',
            [TransactionIntake::INTAKE_STATUS_REJECTED, $since, $minRejections]
        );

        if ($candidates === []) {
            return;
        }

        // 2) which candidates also have a non-rejected intake, chunked, via
        // idx_intake_tenant_terminal_status_received.
        $accepted = [];
        foreach (array_chunk($candidates, max(1, $this->noAcceptChunkSize)) as $chunk) {
            $bindings = [];
            foreach ($chunk as $row) {
                $bindings[] = (int) $row->tenant_id;
                $bindings[] = (int) $row->terminal_id;
            }
            $statusPlaceholders = implode(', ', array_fill(0, count(self::NON_REJECTED_INTAKE_STATUSES), '?'));
            array_push($bindings, ...self::NON_REJECTED_INTAKE_STATUSES);
            $bindings[] = $since;

            $rows = $this->query(
                'SELECT '.$this->hint().'DISTINCT tenant_id, terminal_id
                   FROM transaction_intake
                  WHERE (tenant_id, terminal_id) IN ('.implode(', ', array_fill(0, count($chunk), '(?, ?)')).')
                    AND intake_status IN ('.$statusPlaceholders.')
                    AND received_at >= ?',
                $bindings
            );

            foreach ($rows as $row) {
                $accepted[(int) $row->tenant_id.':'.(int) $row->terminal_id] = true;
            }
        }

        foreach ($candidates as $row) {
            if (! isset($accepted[(int) $row->tenant_id.':'.(int) $row->terminal_id])) {
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

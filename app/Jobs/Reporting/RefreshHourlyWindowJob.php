<?php

namespace App\Jobs\Reporting;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefreshHourlyWindowJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $from; // ISO8601 string
    public $to;   // ISO8601 string
    public $tenantId;
    public $terminalId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $from, string $to, $tenantId = null, $terminalId = null)
    {
        $this->from = $from;
        $this->to = $to;
        $this->tenantId = $tenantId;
        $this->terminalId = $terminalId;
        $this->onQueue('reporting');
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        try {
            $conn = DB::connection('reporting');

            // Upsert hourly aggregates for the given window (safe to run multiple times)
            $sql = "INSERT INTO transactions_hourly (tenant_id, terminal_id, hour, tx_count, total_amount, avg_amount, min_amount, max_amount, success_count, decline_count, issues_count, issues_amount, duplicate_count, created_at, updated_at)\n".
                "SELECT tenant_id, COALESCE(terminal_id, 0) AS terminal_id, DATE_FORMAT(transaction_timestamp, '%Y-%m-%d %H:00:00') AS hour,\n".
                "  COUNT(*) AS tx_count, SUM(gross_sales) AS total_amount, AVG(gross_sales) AS avg_amount, MIN(gross_sales) AS min_amount, MAX(gross_sales) AS max_amount,\n".
                "  SUM(CASE WHEN transaction_type = 'success' THEN 1 ELSE 0 END) AS success_count,\n".
                "  SUM(CASE WHEN transaction_type = 'decline' THEN 1 ELSE 0 END) AS decline_count,\n".
                "  SUM(CASE WHEN validation_status = 'WITH_ISSUES' THEN 1 ELSE 0 END) AS issues_count,\n".
                "  SUM(CASE WHEN validation_status = 'WITH_ISSUES' THEN gross_sales ELSE 0 END) AS issues_amount,\n".
                "  SUM(CASE WHEN COALESCE(is_duplicate, 0) = 1 THEN 1 ELSE 0 END) AS duplicate_count, NOW() AS created_at, NOW() AS updated_at\n".
                "FROM transactions\n".
                "WHERE transaction_timestamp >= ? AND transaction_timestamp < ?\n";

            $params = [$this->from, $this->to];

            if ($this->tenantId) {
                $sql .= " AND tenant_id = ?\n";
                $params[] = $this->tenantId;
            }
            if ($this->terminalId) {
                $sql .= " AND terminal_id = ?\n";
                $params[] = $this->terminalId;
            }

            $sql .= "GROUP BY tenant_id, terminal_id, hour\n".
                "ON DUPLICATE KEY UPDATE\n".
                "  tx_count = VALUES(tx_count), total_amount = VALUES(total_amount), avg_amount = VALUES(avg_amount), min_amount = VALUES(min_amount), max_amount = VALUES(max_amount),\n".
                "  success_count = VALUES(success_count), decline_count = VALUES(decline_count), issues_count = VALUES(issues_count), issues_amount = VALUES(issues_amount), duplicate_count = VALUES(duplicate_count), updated_at = VALUES(updated_at)";

            $conn->statement($sql, $params);
            Log::info('Reporting job completed window', ['from' => $this->from, 'to' => $this->to, 'tenant' => $this->tenantId, 'terminal' => $this->terminalId]);
        } catch (\Throwable $e) {
            Log::error('Reporting job failed', ['error' => $e->getMessage(), 'from' => $this->from, 'to' => $this->to]);
            throw $e; // allow retry
        }
    }
}

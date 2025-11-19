<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReportingRefreshCommand extends Command
{
    // Support both the original positional arg and a --table option for
    // backward compatibility with docs/scripts that previously used
    // `--table=...`. The command will prefer the explicit `--table`
    // option if provided, otherwise it falls back to the positional arg.
    protected $signature = 'reporting:refresh {tableArg=transactions_hourly} {--hours=3} {--table=}';

    protected $description = 'Refresh reporting summary tables (incremental upsert). Supports positional tableArg or --table option.';

    public function handle()
    {
    // Prefer explicit --table option when provided (back-compat), else use positional arg
    $table = $this->option('table') ?: $this->argument('tableArg');
    $hours = intval($this->option('hours'));

        $this->info("Refreshing summary table: $table for last $hours hours");

        if ($table === 'transactions_hourly') {
            $this->refreshTransactionsHourly($hours);
            return 0;
        }

        if ($table === 'transactions_daily') {
            $this->refreshTransactionsDaily();
            return 0;
        }

        $this->error("Unknown summary table: $table");
        return 1;
    }

    protected function refreshTransactionsHourly(int $hours)
    {
        $hours = max(1, $hours);
        $this->info("Computing aggregates for last $hours hours...");

        // Build SQL for MySQL: aggregate from transactions and upsert into transactions_hourly
        $sql = "INSERT INTO transactions_hourly (tenant_id, terminal_id, hour, tx_count, total_amount, avg_amount, min_amount, max_amount, success_count, decline_count, issues_count, issues_amount, duplicate_count, created_at, updated_at)\n".
            "SELECT\n".
            "  tenant_id, COALESCE(terminal_id, 0) AS terminal_id, DATE_FORMAT(transaction_timestamp, '%Y-%m-%d %H:00:00') AS hour,\n".
            "  COUNT(*) AS tx_count,\n".
            "  SUM(gross_sales) AS total_amount,\n".
            "  AVG(gross_sales) AS avg_amount,\n".
            "  MIN(gross_sales) AS min_amount,\n".
            "  MAX(gross_sales) AS max_amount,\n".
            "  SUM(CASE WHEN transaction_type = 'success' THEN 1 ELSE 0 END) AS success_count,\n".
            "  SUM(CASE WHEN transaction_type = 'decline' THEN 1 ELSE 0 END) AS decline_count,\n".
            "  SUM(CASE WHEN validation_status = 'WITH_ISSUES' THEN 1 ELSE 0 END) AS issues_count,\n".
            "  SUM(CASE WHEN validation_status = 'WITH_ISSUES' THEN gross_sales ELSE 0 END) AS issues_amount,\n".
            "  SUM(CASE WHEN COALESCE(is_duplicate, 0) = 1 THEN 1 ELSE 0 END) AS duplicate_count,\n".
            "  NOW() AS created_at, NOW() AS updated_at\n".
            "FROM transactions\n".
            "WHERE transaction_timestamp >= DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), INTERVAL {$hours} HOUR)\n".
            "GROUP BY tenant_id, terminal_id, hour\n".
            "ON DUPLICATE KEY UPDATE\n".
            "  tx_count = VALUES(tx_count),\n".
            "  total_amount = VALUES(total_amount),\n".
            "  avg_amount = VALUES(avg_amount),\n".
            "  min_amount = VALUES(min_amount),\n".
            "  max_amount = VALUES(max_amount),\n".
            "  success_count = VALUES(success_count),\n".
            "  decline_count = VALUES(decline_count),\n".
            "  issues_count = VALUES(issues_count),\n".
            "  issues_amount = VALUES(issues_amount),\n".
            "  duplicate_count = VALUES(duplicate_count),\n".
            "  updated_at = VALUES(updated_at)";

        try {
            $affected = DB::connection('reporting')->statement($sql);
            $this->info('Refresh executed (check rows via select)');
            Log::info('Reporting refresh executed for transactions_hourly', ['hours' => $hours]);
        } catch (\Exception $e) {
            $this->error('Failed to refresh transactions_hourly: ' . $e->getMessage());
            Log::error('Reporting refresh failed', ['error' => $e->getMessage()]);
        }
    }

    protected function refreshTransactionsDaily()
    {
        $this->info('Refreshing transactions_daily for last 2 days (default)');

        $sql = "INSERT INTO transactions_daily (tenant_id, terminal_id, date, tx_count, total_amount, avg_amount, issues_count, issues_amount, created_at, updated_at)\n".
            "SELECT\n".
            "  tenant_id, COALESCE(terminal_id, 0) AS terminal_id, DATE(transaction_timestamp) AS date,\n".
            "  COUNT(*) AS tx_count, SUM(gross_sales) AS total_amount, AVG(gross_sales) AS avg_amount,\n".
            "  SUM(CASE WHEN validation_status = 'WITH_ISSUES' THEN 1 ELSE 0 END) AS issues_count,\n".
            "  SUM(CASE WHEN validation_status = 'WITH_ISSUES' THEN gross_sales ELSE 0 END) AS issues_amount,\n".
            "  NOW() AS created_at, NOW() AS updated_at\n".
            "FROM transactions\n".
            "WHERE transaction_timestamp >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)\n".
            "GROUP BY tenant_id, terminal_id, date\n".
            "ON DUPLICATE KEY UPDATE\n".
            "  tx_count = VALUES(tx_count),\n".
            "  total_amount = VALUES(total_amount),\n".
            "  avg_amount = VALUES(avg_amount),\n".
            "  issues_count = VALUES(issues_count),\n".
            "  issues_amount = VALUES(issues_amount),\n".
            "  updated_at = VALUES(updated_at)";

        try {
            DB::connection('reporting')->statement($sql);
            $this->info('transactions_daily refresh executed');
            Log::info('Reporting refresh executed for transactions_daily');
        } catch (\Exception $e) {
            $this->error('Failed to refresh transactions_daily: ' . $e->getMessage());
            Log::error('Reporting refresh failed (daily)', ['error' => $e->getMessage()]);
        }
    }
}

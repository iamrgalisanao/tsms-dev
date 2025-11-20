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

        // Build SQL for MySQL: aggregate from primary `transactions` table and upsert into reporting.transactions_hourly
        // We execute the query on the primary DB connection but write into the reporting database using a fully-qualified table name.
        $reportingDb = DB::connection('reporting')->getDatabaseName();
        $insertInto = sprintf('`%s`.transactions_hourly', $reportingDb);

        // Some deployments may have divergent `transactions` schemas (e.g. missing is_duplicate).
        // Detect optional columns and adapt the SELECT to avoid SQL errors on servers without the column.
        $hasIsDuplicate = false;
        try {
            $hasIsDuplicate = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'is_duplicate');
        } catch (\Throwable $e) {
            // If Schema check fails, assume column is absent to be safe
            $hasIsDuplicate = false;
        }

        $duplicateSelect = $hasIsDuplicate
            ? "SUM(CASE WHEN COALESCE(is_duplicate, 0) = 1 THEN 1 ELSE 0 END) AS duplicate_count,\n"
            : "0 AS duplicate_count,\n";

        // Build select fragments with runtime guards so we don't fail if the raw
        // `transactions` table in some deployments lacks optional columns.
        $hasNet = false; $hasDiscount = false; $hasVat = false; $hasSc = false;
        $hasVoided = false; $hasRefund = false; $hasPaymentMethod = false; $hasChannel = false; $hasPrimary = false; $hasTxId = false; $hasCompletedAt = false;
        try {
            $schema = \Illuminate\Support\Facades\Schema::getFacadeRoot();
            $hasNet = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'net_sales');
            $hasDiscount = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'discount_total') || \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'promo_discount');
            $hasVat = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'vat_amount') || \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'tax_amount');
            $hasSc = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'service_charge');
            $hasVoided = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'voided_at');
            $hasRefund = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'refund_amount') || \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'refund_status');
            $hasPaymentMethod = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'payment_method');
            $hasChannel = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'channel');
            $hasPrimary = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'primary_category');
            $hasTxId = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'id') || \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'transaction_pk');
            $hasCompletedAt = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'completed_at') || \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'transaction_timestamp');
        } catch (\Throwable $e) {
            // ignore and leave flags false
        }

        $netSelect = $hasNet ? "SUM(COALESCE(net_sales,0)) AS total_net_amount,\n" : "0 AS total_net_amount,\n";
        // Build discount select while referencing only the columns that actually exist.
        if ($hasDiscount) {
            $hasDiscountTotal = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'discount_total');
            $hasPromoDiscount = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'promo_discount');
            if ($hasDiscountTotal && $hasPromoDiscount) {
                $discountSelect = "SUM(COALESCE(discount_total, promo_discount, 0)) AS total_discount_amount,\n";
            } elseif ($hasDiscountTotal) {
                $discountSelect = "SUM(COALESCE(discount_total, 0)) AS total_discount_amount,\n";
            } else {
                $discountSelect = "SUM(COALESCE(promo_discount, 0)) AS total_discount_amount,\n";
            }
        } else {
            $discountSelect = "0 AS total_discount_amount,\n";
        }

        // Build VAT/tax select while referencing only existing columns to avoid unknown-column SQL errors.
        if ($hasVat) {
            $hasVatAmount = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'vat_amount');
            $hasTaxAmount = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'tax_amount');
            if ($hasVatAmount && $hasTaxAmount) {
                $vatSelect = "SUM(COALESCE(vat_amount, tax_amount, 0)) AS total_tax_amount,\n";
            } elseif ($hasVatAmount) {
                $vatSelect = "SUM(COALESCE(vat_amount, 0)) AS total_tax_amount,\n";
            } else {
                $vatSelect = "SUM(COALESCE(tax_amount, 0)) AS total_tax_amount,\n";
            }
        } else {
            $vatSelect = "0 AS total_tax_amount,\n";
        }
        $scSelect = $hasSc ? "SUM(COALESCE(service_charge,0)) AS total_service_charge_amount,\n" : "0 AS total_service_charge_amount,\n";
        $voidSelect = $hasVoided ? "SUM(CASE WHEN voided_at IS NOT NULL THEN 1 ELSE 0 END) AS void_count,\n" : "0 AS void_count,\n";
        // Build refunded_count select referencing only existing refund-related columns
        if ($hasRefund) {
            $hasRefundAmount = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'refund_amount');
            $hasRefundStatus = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'refund_status');
            if ($hasRefundAmount && $hasRefundStatus) {
                $refSelect = "SUM(CASE WHEN COALESCE(refund_amount,0) > 0 OR refund_status = 'REFUNDED' THEN 1 ELSE 0 END) AS refunded_count,\n";
            } elseif ($hasRefundAmount) {
                $refSelect = "SUM(CASE WHEN COALESCE(refund_amount,0) > 0 THEN 1 ELSE 0 END) AS refunded_count,\n";
            } else {
                $refSelect = "SUM(CASE WHEN refund_status = 'REFUNDED' THEN 1 ELSE 0 END) AS refunded_count,\n";
            }
        } else {
            $refSelect = "0 AS refunded_count,\n";
        }
    // sample_* columns intentionally omitted from insert since reporting table no longer stores sample payloads
    $sampleTxIdSelect = '';
    $sampleCompletedAtSelect = '';
    $samplePaymentSelect = '';
    $sampleChannelSelect = '';
    $samplePrimarySelect = '';

    $sql = "INSERT INTO " . $insertInto . " (tenant_id, terminal_id, hour, tx_count, total_amount, total_gross_amount, total_net_amount, total_discount_amount, total_tax_amount, total_service_charge_amount, avg_amount, min_amount, max_amount, success_count, decline_count, issues_count, issues_amount, void_count, refunded_count, duplicate_count, created_at, updated_at)\n".
            "SELECT\n".
            "  tenant_id, COALESCE(terminal_id, 0) AS terminal_id, DATE_FORMAT(transaction_timestamp, '%Y-%m-%d %H:00:00') AS hour,\n".
            "  COUNT(*) AS tx_count,\n".
            "  SUM(gross_sales) AS total_amount,\n".
            "  SUM(gross_sales) AS total_gross_amount,\n".
            $netSelect.
            $discountSelect.
            $vatSelect.
            $scSelect.
            "  AVG(gross_sales) AS avg_amount,\n".
            "  MIN(gross_sales) AS min_amount,\n".
            "  MAX(gross_sales) AS max_amount,\n".
            "  SUM(CASE WHEN transaction_type = 'success' THEN 1 ELSE 0 END) AS success_count,\n".
            "  SUM(CASE WHEN transaction_type = 'decline' THEN 1 ELSE 0 END) AS decline_count,\n".
            "  SUM(CASE WHEN validation_status = 'WITH_ISSUES' THEN 1 ELSE 0 END) AS issues_count,\n".
            "  SUM(CASE WHEN validation_status = 'WITH_ISSUES' THEN gross_sales ELSE 0 END) AS issues_amount,\n".
            $voidSelect.
            $refSelect.
            // sample_* selection omitted (resolved at application layer when needed)
            $duplicateSelect.
            "  NOW() AS created_at, NOW() AS updated_at\n".
            "FROM transactions\n".
            "WHERE transaction_timestamp >= DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), INTERVAL {$hours} HOUR)\n".
            "GROUP BY tenant_id, terminal_id, hour\n".
            "ON DUPLICATE KEY UPDATE\n".
            "  tx_count = VALUES(tx_count),\n".
            "  total_amount = VALUES(total_amount),\n".
            "  total_gross_amount = VALUES(total_gross_amount),\n".
            "  total_net_amount = VALUES(total_net_amount),\n".
            "  total_discount_amount = VALUES(total_discount_amount),\n".
            "  total_tax_amount = VALUES(total_tax_amount),\n".
            "  total_service_charge_amount = VALUES(total_service_charge_amount),\n".
            "  avg_amount = VALUES(avg_amount),\n".
            "  min_amount = VALUES(min_amount),\n".
            "  max_amount = VALUES(max_amount),\n".
            "  success_count = VALUES(success_count),\n".
            "  decline_count = VALUES(decline_count),\n".
            "  issues_count = VALUES(issues_count),\n".
            "  issues_amount = VALUES(issues_amount),\n".
            "  void_count = VALUES(void_count),\n".
            "  refunded_count = VALUES(refunded_count),\n".
            // sample_* update omitted
            "  duplicate_count = VALUES(duplicate_count),\n".
            "  updated_at = VALUES(updated_at)";

        try {
            // Run the aggregation on the primary DB connection (default) so we read the canonical transactions schema
            DB::statement($sql);
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

        $reportingDb = DB::connection('reporting')->getDatabaseName();
        $insertIntoDaily = sprintf('`%s`.transactions_daily', $reportingDb);

        $sql = "INSERT INTO " . $insertIntoDaily . " (tenant_id, terminal_id, date, tx_count, total_amount, avg_amount, issues_count, issues_amount, created_at, updated_at)\n".
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
            // Execute on primary connection and write into reporting DB via fully-qualified table name
            DB::statement($sql);
            $this->info('transactions_daily refresh executed');
            Log::info('Reporting refresh executed for transactions_daily');
        } catch (\Exception $e) {
            $this->error('Failed to refresh transactions_daily: ' . $e->getMessage());
            Log::error('Reporting refresh failed (daily)', ['error' => $e->getMessage()]);
        }
    }
}

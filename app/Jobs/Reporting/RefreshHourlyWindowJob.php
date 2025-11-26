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
        // Short-circuit: allow runtime disabling of this job via env var.
        // Set DISABLE_REFRESH_HOURLY_WINDOW_JOB=true to skip execution.
        try {
            if (filter_var(env('DISABLE_REFRESH_HOURLY_WINDOW_JOB', 'true'), FILTER_VALIDATE_BOOLEAN)) {
                Log::info('RefreshHourlyWindowJob is disabled via DISABLE_REFRESH_HOURLY_WINDOW_JOB; skipping execution.', ['from' => $this->from, 'to' => $this->to, 'tenant' => $this->tenantId, 'terminal' => $this->terminalId]);
                return;
            }
        } catch (\Throwable $e) {
            // If something odd happens reading the env, proceed with normal execution
        }
        try {
            // We'll run the aggregation on the primary (default) connection so
            // we always read the authoritative `transactions` schema and then
            // insert into the reporting DB using a fully-qualified table name.
            $primary = DB::connection();
            $reportingDb = DB::connection('reporting')->getDatabaseName();
            $insertInto = sprintf('`%s`.transactions_hourly', $reportingDb);

            // Runtime detection of optional raw columns to avoid SQL errors when schemas diverge
            $hasNet = false; $hasDiscount = false; $hasVat = false; $hasSc = false;
            $hasVoided = false; $hasRefund = false; $hasPaymentMethod = false; $hasChannel = false; $hasPrimary = false; $hasTxId = false; $hasCompletedAt = false;
            try {
                $hasNet = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'net_sales');
                $hasDiscount = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'discount_total') || \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'promo_discount');
                $hasVat = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'vat_amount') || \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'tax_amount');
                $hasSc = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'service_charge');
                $hasVoided = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'voided_at');
                $hasRefund = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'refund_amount') || \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'refund_status');
                $hasPaymentMethod = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'payment_method');
                $hasChannel = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'channel');
                $hasPrimary = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'primary_category');
                $hasTxId = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'id');
                $hasCompletedAt = \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'completed_at') || \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'transaction_timestamp');
            } catch (\Throwable $e) {
                // leave bools false
            }

            $netSelect = $hasNet ? "SUM(COALESCE(net_sales,0)) AS total_net_amount,\n" : "0 AS total_net_amount,\n";
            $discountSelect = $hasDiscount ? "SUM(COALESCE(discount_total, COALESCE(promo_discount,0),0)) AS total_discount_amount,\n" : "0 AS total_discount_amount,\n";
            $vatSelect = $hasVat ? "SUM(COALESCE(vat_amount, COALESCE(tax_amount,0),0)) AS total_tax_amount,\n" : "0 AS total_tax_amount,\n";
            $scSelect = $hasSc ? "SUM(COALESCE(service_charge,0)) AS total_service_charge_amount,\n" : "0 AS total_service_charge_amount,\n";
            $voidSelect = $hasVoided ? "SUM(CASE WHEN voided_at IS NOT NULL THEN 1 ELSE 0 END) AS void_count,\n" : "0 AS void_count,\n";
            $refSelect = $hasRefund ? "SUM(CASE WHEN COALESCE(refund_amount,0) > 0 OR refund_status = 'REFUNDED' THEN 1 ELSE 0 END) AS refunded_count,\n" : "0 AS refunded_count,\n";
            $sampleTxIdSelect = $hasTxId ? "MIN(id) AS sample_transaction_id,\n" : "NULL AS sample_transaction_id,\n";
            $sampleCompletedAtSelect = $hasCompletedAt ? "MIN(COALESCE(completed_at, transaction_timestamp)) AS sample_completed_at,\n" : "NULL AS sample_completed_at,\n";
            $samplePaymentSelect = $hasPaymentMethod ? "SUBSTRING_INDEX(GROUP_CONCAT(payment_method ORDER BY transaction_timestamp DESC SEPARATOR '|'), '|', 1) AS sample_payment_method,\n" : "NULL AS sample_payment_method,\n";
            $sampleChannelSelect = $hasChannel ? "SUBSTRING_INDEX(GROUP_CONCAT(channel ORDER BY transaction_timestamp DESC SEPARATOR '|'), '|', 1) AS sample_channel,\n" : "NULL AS sample_channel,\n";
            $samplePrimarySelect = $hasPrimary ? "SUBSTRING_INDEX(GROUP_CONCAT(primary_category ORDER BY transaction_timestamp DESC SEPARATOR '|'), '|', 1) AS sample_primary_category,\n" : "NULL AS sample_primary_category,\n";

            // Upsert hourly aggregates for the given window (safe to run multiple times)
            $sql = "INSERT INTO " . $insertInto . " (tenant_id, terminal_id, hour, tx_count, total_amount, total_gross_amount, total_net_amount, total_discount_amount, total_tax_amount, total_service_charge_amount, avg_amount, min_amount, max_amount, success_count, decline_count, issues_count, issues_amount, void_count, refunded_count, sample_transaction_id, sample_completed_at, sample_payment_method, sample_channel, sample_primary_category, duplicate_count, created_at, updated_at)\n".
                "SELECT tenant_id, COALESCE(terminal_id, 0) AS terminal_id, DATE_FORMAT(transaction_timestamp, '%Y-%m-%d %H:00:00') AS hour,\n".
                "  COUNT(*) AS tx_count, SUM(gross_sales) AS total_amount, SUM(gross_sales) AS total_gross_amount,\n".
                $netSelect.
                $discountSelect.
                $vatSelect.
                $scSelect.
                "  AVG(gross_sales) AS avg_amount, MIN(gross_sales) AS min_amount, MAX(gross_sales) AS max_amount,\n".
                "  SUM(CASE WHEN transaction_type = 'success' THEN 1 ELSE 0 END) AS success_count,\n".
                "  SUM(CASE WHEN transaction_type = 'decline' THEN 1 ELSE 0 END) AS decline_count,\n".
                "  SUM(CASE WHEN validation_status = 'WITH_ISSUES' THEN 1 ELSE 0 END) AS issues_count,\n".
                "  SUM(CASE WHEN validation_status = 'WITH_ISSUES' THEN gross_sales ELSE 0 END) AS issues_amount,\n".
                $voidSelect.
                $refSelect.
                $sampleTxIdSelect.
                $sampleCompletedAtSelect.
                $samplePaymentSelect.
                $sampleChannelSelect.
                $samplePrimarySelect.
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
                "  tx_count = VALUES(tx_count), total_amount = VALUES(total_amount), total_gross_amount = VALUES(total_gross_amount), total_net_amount = VALUES(total_net_amount), total_discount_amount = VALUES(total_discount_amount), total_tax_amount = VALUES(total_tax_amount), total_service_charge_amount = VALUES(total_service_charge_amount), avg_amount = VALUES(avg_amount), min_amount = VALUES(min_amount), max_amount = VALUES(max_amount),\n".
                "  success_count = VALUES(success_count), decline_count = VALUES(decline_count), issues_count = VALUES(issues_count), issues_amount = VALUES(issues_amount), void_count = VALUES(void_count), refunded_count = VALUES(refunded_count), sample_transaction_id = VALUES(sample_transaction_id), sample_completed_at = VALUES(sample_completed_at), sample_payment_method = VALUES(sample_payment_method), sample_channel = VALUES(sample_channel), sample_primary_category = VALUES(sample_primary_category), duplicate_count = VALUES(duplicate_count), updated_at = VALUES(updated_at)";

            // Execute on the primary connection so the SELECT reads the canonical transactions table
            $primary->statement($sql, $params);
            Log::info('Reporting job completed window', ['from' => $this->from, 'to' => $this->to, 'tenant' => $this->tenantId, 'terminal' => $this->terminalId]);
        } catch (\Throwable $e) {
            Log::error('Reporting job failed', ['error' => $e->getMessage(), 'from' => $this->from, 'to' => $this->to]);
            throw $e; // allow retry
        }
    }
}

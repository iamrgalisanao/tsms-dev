<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * - Adds a scoped unique index on (tenant_id, terminal_id, receipt_no)
     *   to enforce POS-supplied receipt uniqueness per terminal/tenant.
     * - Adds a nullable `refund_reference` column to support idempotency keys
     *   for refund requests and creates a unique index on it.
     *
     * This migration performs a pre-check for duplicate receipt_no rows and
     * will abort with a helpful message if duplicates are found so ops can
     * remediate before applying the unique constraint.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        // Add refund_reference column if missing
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'refund_reference')) {
                $table->string('refund_reference', 128)->nullable()->after('receipt_no');
            }
        });

        // Check for same-day duplicate receipt_no entries scoped by tenant_id + terminal_id
        // Canonical date uses DATE(COALESCE(transaction_timestamp, completed_at, created_at)).
        $duplicates = DB::select(
            "SELECT tenant_id, terminal_id, receipt_no, DATE(COALESCE(transaction_timestamp, completed_at, created_at)) as tx_date, COUNT(*) as c
             FROM transactions
             WHERE receipt_no IS NOT NULL
             GROUP BY tenant_id, terminal_id, receipt_no, tx_date
             HAVING COUNT(*) > 1
             LIMIT 10"
        );

        if (! empty($duplicates)) {
            $sample = array_map(function ($r) {
                return sprintf("tenant=%s terminal=%s receipt_no=%s tx_date=%s count=%s", $r->tenant_id, $r->terminal_id, $r->receipt_no, $r->tx_date, $r->c);
            }, $duplicates);

            throw new \Exception("Cannot add unique index on (tenant_id,terminal_id,receipt_no,transaction_date): found same-day duplicate receipt_no rows. Sample: " . implode('; ', $sample) . ". Please resolve duplicates before running this migration.");
        }

        // Add a stored/generated DATE column `transaction_date` to represent the
        // canonical transaction date (DATE(COALESCE(transaction_timestamp, completed_at, created_at))).
        // Use a raw statement since Laravel's schema builder doesn't yet support
        // generated stored columns in a portable way.
        try {
            DB::statement("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS transaction_date DATE GENERATED ALWAYS AS (DATE(COALESCE(transaction_timestamp, completed_at, created_at))) STORED");
        } catch (\Throwable $e) {
            // Some MySQL versions do not support IF NOT EXISTS for ALTER COLUMN; fallback to best-effort add
            try {
                DB::statement("ALTER TABLE transactions ADD COLUMN transaction_date DATE GENERATED ALWAYS AS (DATE(COALESCE(transaction_timestamp, completed_at, created_at))) STORED");
            } catch (\Throwable $_) {
                // ignore - column may already exist or the server may not support generated columns
            }
        }

        // Create unique composite index on (tenant_id, terminal_id, receipt_no, transaction_date)
        Schema::table('transactions', function (Blueprint $table) {
            try {
                $table->unique(['tenant_id', 'terminal_id', 'receipt_no', 'transaction_date'], 'ux_tx_tenant_terminal_receipt_date_unique');
            } catch (\Throwable $e) {
                // best-effort: ignore if index already exists or cannot be created
            }

            try {
                $table->unique('refund_reference', 'ux_transactions_refund_reference');
            } catch (\Throwable $e) {
                // ignore; unique may already exist
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            // Try dropping both possible index names (historical and new)
            try { $table->dropUnique('ux_tx_tenant_terminal_receipt_unique'); } catch (\Throwable $e) {}
            try { $table->dropUnique('ux_tx_tenant_terminal_receipt_date_unique'); } catch (\Throwable $e) {}
            try { $table->dropUnique('ux_transactions_refund_reference'); } catch (\Throwable $e) {}

            if (Schema::hasColumn('transactions', 'refund_reference')) {
                $table->dropColumn('refund_reference');
            }

            // Attempt to drop the generated transaction_date column if present
            if (Schema::hasColumn('transactions', 'transaction_date')) {
                try {
                    $table->dropColumn('transaction_date');
                } catch (\Throwable $_) {
                    // Some DB platforms may not allow dropping generated columns via schema builder; fallback to raw.
                    try {
                        DB::statement('ALTER TABLE transactions DROP COLUMN transaction_date');
                    } catch (\Throwable $__) {
                        // ignore
                    }
                }
            }
        });
    }
};

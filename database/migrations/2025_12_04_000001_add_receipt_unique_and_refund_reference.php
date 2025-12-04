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

        // Check for duplicate receipt_no entries scoped by tenant_id + terminal_id
        $duplicates = DB::select(
            "SELECT tenant_id, terminal_id, receipt_no, COUNT(*) as c
             FROM transactions
             WHERE receipt_no IS NOT NULL
             GROUP BY tenant_id, terminal_id, receipt_no
             HAVING COUNT(*) > 1
             LIMIT 10"
        );

        if (! empty($duplicates)) {
            $sample = array_map(function ($r) {
                return sprintf("tenant=%s terminal=%s receipt_no=%s count=%s", $r->tenant_id, $r->terminal_id, $r->receipt_no, $r->c);
            }, $duplicates);

            throw new \Exception("Cannot add unique index on (tenant_id,terminal_id,receipt_no): found duplicate receipt_no rows. Sample: " . implode('; ', $sample) . ". Please resolve duplicates before running this migration.");
        }

        // Create unique composite index (scoped to tenant+terminal+receipt)
        Schema::table('transactions', function (Blueprint $table) {
            try {
                $table->unique(['tenant_id', 'terminal_id', 'receipt_no'], 'ux_tx_tenant_terminal_receipt_unique');
            } catch (\Throwable $e) {
                // best-effort: ignore if index already exists or cannot be created in this environment
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
            try { $table->dropUnique('ux_tx_tenant_terminal_receipt_unique'); } catch (\Throwable $e) {}
            try { $table->dropUnique('ux_transactions_refund_reference'); } catch (\Throwable $e) {}

            if (Schema::hasColumn('transactions', 'refund_reference')) {
                $table->dropColumn('refund_reference');
            }
        });
    }
};

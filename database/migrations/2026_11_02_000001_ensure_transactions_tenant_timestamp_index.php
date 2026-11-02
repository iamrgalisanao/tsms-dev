<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-asserts idx_tx_logs_tenant_timestamp, originally created by
 * 2026_06_16_000001_add_transaction_log_performance_indexes. Staging records that
 * migration as ran but the index is absent, so tenant-scoped CMSR / daily-summary
 * queries have no (tenant_id, transaction_timestamp) range index to use.
 *
 * Same name and columns as the original, so this is a no-op wherever it already exists.
 */
return new class extends Migration
{
    private const INDEX = 'idx_tx_logs_tenant_timestamp';

    public function up(): void
    {
        if (! Schema::hasTable('transactions')
            || ! Schema::hasColumn('transactions', 'tenant_id')
            || ! Schema::hasColumn('transactions', 'transaction_timestamp')
            || $this->indexExists()) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['tenant_id', 'transaction_timestamp', 'id'], self::INDEX);
        });
    }

    public function down(): void
    {
        // Intentionally empty: the index belongs to 2026_06_16_000001, whose down() drops it.
    }

    private function indexExists(): bool
    {
        return ! empty(DB::select('SHOW INDEX FROM transactions WHERE Key_name = ?', [self::INDEX]));
    }
};

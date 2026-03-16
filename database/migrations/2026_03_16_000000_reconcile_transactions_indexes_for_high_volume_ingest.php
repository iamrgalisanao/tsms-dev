<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{

    public function up(): void
    {
        $table = 'transactions';

        if (! $this->indexExists($table, 'ux_transactions_transaction_id')) {
            if ($this->hasDuplicateTransactionIds()) {
                throw new RuntimeException(
                    'Cannot add ux_transactions_transaction_id: duplicate transaction_id values exist.'
                );
            }
            Schema::table($table, function (Blueprint $table) {
                $table->unique('transaction_id', 'ux_transactions_transaction_id');
            });
        }

        if (! $this->indexExists($table, 'idx_transactions_terminal_id')) {
            Schema::table($table, function (Blueprint $table) {
                $table->index('terminal_id', 'idx_transactions_terminal_id');
            });
        }

        $this->dropIndexIfExists($table, 'trx_terminal_transaction_unique');
        $this->dropIndexIfExists($table, 'trx_terminal_submission_lookup');
        $this->dropIndexIfExists($table, 'transactions_terminal_receipt_idx');
        $this->dropIndexIfExists($table, 'idx_tx_tenant_terminal_receipt');
        $this->dropIndexIfExists($table, 'idx_transactions_refunded');
        $this->dropIndexIfExists($table, 'idx_transactions_type');
    }


    /**
     * Note: Rolling back this migration will remove the new idempotency rule (UNIQUE(transaction_id))
     * and reintroduce the old index model. Only perform down() if you are certain this is safe for your environment.
     */
    public function down(): void
    {
        $table = 'transactions';

        $this->dropIndexIfExists($table, 'ux_transactions_transaction_id');
        $this->dropIndexIfExists($table, 'idx_transactions_terminal_id');

        if (! $this->indexExists($table, 'trx_terminal_transaction_unique')) {
            Schema::table($table, function (Blueprint $table) {
                $table->unique(['terminal_id', 'transaction_id'], 'trx_terminal_transaction_unique');
            });
        }

        if (! $this->indexExists($table, 'trx_terminal_submission_lookup')) {
            Schema::table($table, function (Blueprint $table) {
                $table->index(['terminal_id', 'submission_uuid'], 'trx_terminal_submission_lookup');
            });
        }

        if (! $this->indexExists($table, 'transactions_terminal_receipt_idx')) {
            Schema::table($table, function (Blueprint $table) {
                $table->index(['terminal_id', 'receipt_no'], 'transactions_terminal_receipt_idx');
            });
        }

        if (! $this->indexExists($table, 'idx_tx_tenant_terminal_receipt')) {
            Schema::table($table, function (Blueprint $table) {
                $table->index(['tenant_id', 'terminal_id', 'receipt_no'], 'idx_tx_tenant_terminal_receipt');
            });
        }

        if (! $this->indexExists($table, 'idx_transactions_refunded')) {
            Schema::table($table, function (Blueprint $table) {
                $table->index('is_refunded', 'idx_transactions_refunded');
            });
        }

        if (! $this->indexExists($table, 'idx_transactions_type')) {
            Schema::table($table, function (Blueprint $table) {
                $table->index('transaction_type', 'idx_transactions_type');
            });
        }
    }
    private function hasDuplicateTransactionIds(): bool
    {
        $table = 'transactions';

        $row = DB::selectOne("
            SELECT 1
            FROM `{$table}`
            GROUP BY transaction_id
            HAVING COUNT(*) > 1
            LIMIT 1
        ");

        return $row !== null;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $db = DB::getDatabaseName();

        $result = DB::selectOne(
            "SELECT 1
             FROM information_schema.statistics
             WHERE table_schema = ?
               AND table_name = ?
               AND index_name = ?
             LIMIT 1",
            [$db, $table, $indexName]
        );

        return $result !== null;
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        $addIndex = function (array $columns, string $name): void {
            if ($this->indexExists($name)) {
                return;
            }

            Schema::table('transactions', function (Blueprint $table) use ($columns, $name) {
                $table->index($columns, $name);
            });
        };

        if (Schema::hasColumn('transactions', 'transaction_date')) {
            $addIndex(['transaction_date', 'id'], 'idx_tx_logs_transaction_date_id');

            if (Schema::hasColumn('transactions', 'tenant_id')) {
                $addIndex(['tenant_id', 'transaction_date', 'id'], 'idx_tx_logs_tenant_transaction_date');
            }

            if (Schema::hasColumn('transactions', 'terminal_id')) {
                $addIndex(['terminal_id', 'transaction_date', 'id'], 'idx_tx_logs_terminal_transaction_date');
            }
        }

        if (Schema::hasColumn('transactions', 'transaction_timestamp')) {
            $addIndex(['transaction_timestamp', 'id'], 'idx_tx_logs_timestamp_id');

            if (Schema::hasColumn('transactions', 'tenant_id')) {
                $addIndex(['tenant_id', 'transaction_timestamp', 'id'], 'idx_tx_logs_tenant_timestamp');
            }

            if (Schema::hasColumn('transactions', 'terminal_id')) {
                $addIndex(['terminal_id', 'transaction_timestamp', 'id'], 'idx_tx_logs_terminal_timestamp');
            }
        }

        if (Schema::hasColumn('transactions', 'completed_at')) {
            $addIndex(['completed_at', 'id'], 'idx_tx_logs_completed_id');
        }

        if (Schema::hasColumn('transactions', 'created_at')) {
            $addIndex(['created_at', 'id'], 'idx_tx_logs_created_id');
        }

        if (Schema::hasColumn('transactions', 'validation_status')) {
            $addIndex(['validation_status', 'id'], 'idx_tx_logs_status_id');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        $dropIndex = function (string $name): void {
            if (! $this->indexExists($name)) {
                return;
            }

            Schema::table('transactions', function (Blueprint $table) use ($name) {
                $table->dropIndex($name);
            });
        };

        $dropIndex('idx_tx_logs_status_id');
        $dropIndex('idx_tx_logs_created_id');
        $dropIndex('idx_tx_logs_completed_id');
        $dropIndex('idx_tx_logs_terminal_timestamp');
        $dropIndex('idx_tx_logs_tenant_timestamp');
        $dropIndex('idx_tx_logs_timestamp_id');
        $dropIndex('idx_tx_logs_terminal_transaction_date');
        $dropIndex('idx_tx_logs_tenant_transaction_date');
        $dropIndex('idx_tx_logs_transaction_date_id');
    }

    private function indexExists(string $name): bool
    {
        return ! empty(DB::select('SHOW INDEX FROM transactions WHERE Key_name = ?', [$name]));
    }
};

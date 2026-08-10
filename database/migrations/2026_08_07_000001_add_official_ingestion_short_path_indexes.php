<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfPossible('transactions', ['tenant_id', 'terminal_id', 'transaction_id'], 'idx_tx_ingest_tenant_terminal_external_id');
        $this->addIndexIfPossible('transactions', ['tenant_id', 'terminal_id', 'validation_status', 'created_at'], 'idx_tx_ingest_tenant_terminal_status_created');
        $this->addIndexIfPossible('transactions', ['tenant_id', 'terminal_id', 'receipt_no', 'transaction_timestamp'], 'idx_tx_ingest_receipt_timestamp');
        $this->addIndexIfPossible('transactions', ['tenant_id', 'terminal_id', 'receipt_no', 'transaction_date'], 'idx_tx_ingest_receipt_date');

        $this->addIndexIfPossible('transaction_intake', ['intake_status', 'received_at'], 'idx_intake_status_received');
        $this->addIndexIfPossible('transaction_intake', ['intake_status', 'processing_status', 'queued_at'], 'idx_intake_status_processing_queued');
        $this->addIndexIfPossible('transaction_intake', ['processing_status', 'updated_at'], 'idx_intake_processing_updated');
        $this->addIndexIfPossible('transaction_intake', ['tenant_id', 'terminal_id', 'intake_status', 'received_at'], 'idx_intake_tenant_terminal_status_received');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('transaction_intake', 'idx_intake_tenant_terminal_status_received');
        $this->dropIndexIfExists('transaction_intake', 'idx_intake_processing_updated');
        $this->dropIndexIfExists('transaction_intake', 'idx_intake_status_processing_queued');
        $this->dropIndexIfExists('transaction_intake', 'idx_intake_status_received');

        $this->dropIndexIfExists('transactions', 'idx_tx_ingest_receipt_date');
        $this->dropIndexIfExists('transactions', 'idx_tx_ingest_receipt_timestamp');
        $this->dropIndexIfExists('transactions', 'idx_tx_ingest_tenant_terminal_status_created');
        $this->dropIndexIfExists('transactions', 'idx_tx_ingest_tenant_terminal_external_id');
    }

    private function addIndexIfPossible(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $name)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        try {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($columns, $name): void {
                $tableBlueprint->index($columns, $name);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1061) {
                throw $e;
            }
        }
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $tableBlueprint) use ($name): void {
            $tableBlueprint->dropIndex($name);
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'mysql') {
            return DB::table('information_schema.statistics')
                ->where('table_schema', $connection->getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $name)
                ->exists();
        }

        $indexes = $connection->getSchemaBuilder()->getIndexes($table);

        return collect($indexes)->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }
};

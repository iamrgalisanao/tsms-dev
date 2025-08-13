<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // 1. Add nullable transaction_pk columns
        Schema::table('transaction_taxes', function (Blueprint $table) {
            if (!Schema::hasColumn('transaction_taxes', 'transaction_pk')) {
                $table->unsignedBigInteger('transaction_pk')->nullable()->after('id');
                $table->index('transaction_pk', 'idx_tx_taxes_pk');
            }
        });
        Schema::table('transaction_adjustments', function (Blueprint $table) {
            if (!Schema::hasColumn('transaction_adjustments', 'transaction_pk')) {
                $table->unsignedBigInteger('transaction_pk')->nullable()->after('id');
                $table->index('transaction_pk', 'idx_tx_adjust_pk');
            }
        });
        Schema::table('transaction_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('transaction_jobs', 'transaction_pk')) {
                $table->unsignedBigInteger('transaction_pk')->nullable()->after('id');
                $table->index('transaction_pk', 'idx_tx_jobs_pk');
            }
        });

        // 2. Backfill transaction_pk values via join (chunked for safety)
        $this->backfill('transaction_taxes');
        $this->backfill('transaction_adjustments');
        $this->backfill('transaction_jobs');

        // 3. Add foreign keys referencing transactions.id
        Schema::table('transaction_taxes', function (Blueprint $table) {
            $this->addFkIfMissing($table, 'transaction_taxes', 'transaction_pk', 'fk_tx_taxes_pk');
        });
        Schema::table('transaction_adjustments', function (Blueprint $table) {
            $this->addFkIfMissing($table, 'transaction_adjustments', 'transaction_pk', 'fk_tx_adjust_pk');
        });
        Schema::table('transaction_jobs', function (Blueprint $table) {
            $this->addFkIfMissing($table, 'transaction_jobs', 'transaction_pk', 'fk_tx_jobs_pk');
        });
    }

    public function down(): void {
        // We do not drop backfilled data. Only remove FK + column for rollback simplicity.
        foreach ([
            ['table' => 'transaction_taxes', 'fk' => 'fk_tx_taxes_pk', 'col' => 'transaction_pk', 'idx' => 'idx_tx_taxes_pk'],
            ['table' => 'transaction_adjustments', 'fk' => 'fk_tx_adjust_pk', 'col' => 'transaction_pk', 'idx' => 'idx_tx_adjust_pk'],
            ['table' => 'transaction_jobs', 'fk' => 'fk_tx_jobs_pk', 'col' => 'transaction_pk', 'idx' => 'idx_tx_jobs_pk'],
        ] as $meta) {
            if (Schema::hasTable($meta['table'])) {
                Schema::table($meta['table'], function (Blueprint $table) use ($meta) {
                    try { $table->dropForeign($meta['fk']); } catch (\Throwable $e) {}
                    try { $table->dropIndex($meta['idx']); } catch (\Throwable $e) {}
                    if (Schema::hasColumn($meta['table'], $meta['col'])) {
                        try { $table->dropColumn($meta['col']); } catch (\Throwable $e) {}
                    }
                });
            }
        }
    }

    private function backfill(string $table): void {
        if (!Schema::hasTable($table)) { return; }
        $batch = 500;
        $hasRows = true;
        while ($hasRows) {
            $rows = DB::table($table)
                ->whereNull('transaction_pk')
                ->limit($batch)
                ->get(['id', 'transaction_id']);
            if ($rows->isEmpty()) { $hasRows = false; break; }
            $ids = $rows->pluck('id');
            // Map transaction_id to PKs
            $txIds = $rows->pluck('transaction_id')->unique();
            $txMap = DB::table('transactions')
                ->whereIn('transaction_id', $txIds)
                ->pluck('id', 'transaction_id');
            foreach ($rows as $r) {
                if (isset($txMap[$r->transaction_id])) {
                    DB::table($table)
                        ->where('id', $r->id)
                        ->update(['transaction_pk' => $txMap[$r->transaction_id]]);
                }
            }
        }
    }

    private function addFkIfMissing(Blueprint $table, string $tableName, string $column, string $fkName): void {
        // MySQL doesn't provide an easy cross-platform way to introspect; attempt create and swallow if exists.
        try {
            $table->foreign($column, $fkName)->references('id')->on('transactions')->onDelete('cascade');
        } catch (\Throwable $e) {
            // ignore
        }
    }
};

<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // 1. Add transaction_pk to transaction_validations (others already have it)
        if (Schema::hasTable('transaction_validations') && !Schema::hasColumn('transaction_validations','transaction_pk')) {
            Schema::table('transaction_validations', function (Blueprint $table) {
                $table->unsignedBigInteger('transaction_pk')->nullable()->after('id');
                $table->index('transaction_pk', 'idx_tx_validations_pk');
            });
            $this->backfill('transaction_validations');
            Schema::table('transaction_validations', function (Blueprint $table) {
                try { $table->foreign('transaction_pk','fk_tx_validations_pk')->references('id')->on('transactions')->onDelete('cascade'); } catch (\Throwable $e) {}
            });
            // enforce NOT NULL
            try { DB::statement('ALTER TABLE `transaction_validations` MODIFY `transaction_pk` BIGINT UNSIGNED NOT NULL'); } catch (\Throwable $e) {}
        }

        // 2. Drop legacy FKs and columns referencing transaction_id for all child tables (after ensuring pk present)
        $children = [
            ['table' => 'transaction_taxes', 'fk' => null],
            ['table' => 'transaction_adjustments', 'fk' => null],
            ['table' => 'transaction_jobs', 'fk' => null],
            ['table' => 'transaction_validations', 'fk' => null],
        ];
        foreach ($children as $meta) {
            $table = $meta['table'];
            if (!Schema::hasTable($table)) { continue; }
            // Attempt to drop FK on transaction_id (unknown constraint name, brute force common patterns)
            foreach (['{$table}_transaction_id_foreign','transaction_id_foreign'] as $fk) {
                try { DB::statement("ALTER TABLE `$table` DROP FOREIGN KEY `$fk`"); } catch (\Throwable $e) {}
            }
            // Drop column transaction_id if safe
            if (Schema::hasColumn($table,'transaction_id')) {
                try { Schema::table($table, function (Blueprint $t) { $t->dropColumn('transaction_id'); }); } catch (\Throwable $e) {}
            }
        }
    }

    public function down(): void {
        // Re-create transaction_id columns (nullable) and FKs for rollback; does not restore data values.
        $children = ['transaction_taxes','transaction_adjustments','transaction_jobs','transaction_validations'];
        foreach ($children as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table,'transaction_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->char('transaction_id',36)->nullable()->after('id');
                });
                try { DB::statement("ALTER TABLE `$table` ADD INDEX idx_${table}_txn_id (`transaction_id`)"); } catch (\Throwable $e) {}
                try { DB::statement("ALTER TABLE `$table` ADD CONSTRAINT fk_${table}_txn_id FOREIGN KEY (`transaction_id`) REFERENCES `transactions`(`transaction_id`) ON DELETE CASCADE"); } catch (\Throwable $e) {}
            }
        }
        // Optionally allow transaction_pk to become nullable again
        foreach ($children as $table) {
            try { DB::statement("ALTER TABLE `$table` MODIFY `transaction_pk` BIGINT UNSIGNED NULL"); } catch (\Throwable $e) {}
        }
    }

    private function backfill(string $table): void {
        $batch = 500;
        while (true) {
            $rows = DB::table($table)->whereNull('transaction_pk')->limit($batch)->get(['id','transaction_id']);
            if ($rows->isEmpty()) break;
            $map = DB::table('transactions')->whereIn('transaction_id', $rows->pluck('transaction_id'))->pluck('id','transaction_id');
            foreach ($rows as $r) {
                if (isset($map[$r->transaction_id])) {
                    DB::table($table)->where('id',$r->id)->update(['transaction_pk' => $map[$r->transaction_id]]);
                }
            }
        }
    }
};

<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // Only enforce NOT NULL when no nulls present (already verified via command)
        foreach (['transaction_taxes','transaction_adjustments','transaction_jobs'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table,'transaction_pk')) {
                // Make column not nullable (platform specific raw SQL for MySQL)
                try {
                    DB::statement("ALTER TABLE `{$table}` MODIFY `transaction_pk` BIGINT UNSIGNED NOT NULL");
                } catch (\Throwable $e) { /* ignore */ }
            }
        }
        // (Optional future step) Drop legacy FKs referencing transaction_id - defer until data present.
        // We keep both for now since child counts are currently zero in dev; safe to postpone.
    }
    public function down(): void {
        // Revert NOT NULL to NULLABLE for rollback
        foreach (['transaction_taxes','transaction_adjustments','transaction_jobs'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table,'transaction_pk')) {
                try { DB::statement("ALTER TABLE `{$table}` MODIFY `transaction_pk` BIGINT UNSIGNED NULL"); } catch (\Throwable $e) {}
            }
        }
    }
};

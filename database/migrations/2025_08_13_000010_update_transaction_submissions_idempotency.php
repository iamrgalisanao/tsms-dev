<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Strengthen idempotency at submission layer
        if (Schema::hasTable('transaction_submissions')) {
            Schema::table('transaction_submissions', function (Blueprint $table) {
                // Drop existing unique index on submission_uuid (if present) to allow composite uniqueness
                try {
                    $table->dropUnique('transaction_submissions_submission_uuid_unique');
                } catch (\Throwable $e) {
                    // Index may already be modified; ignore
                }
                // Add composite unique ensuring submission uuid uniqueness is scoped per terminal
                $table->unique(['terminal_id','submission_uuid'], 'trx_sub_terminal_submission_unique');
            });
        }

        // Add supporting index on transactions for faster lookup by submission
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                // Ensure submission_uuid column exists before indexing
                if (Schema::hasColumn('transactions','submission_uuid')) {
                    // Plain index (already added previously perhaps) - safe to attempt
                    try { $table->index(['terminal_id','submission_uuid'], 'trx_terminal_submission_lookup'); } catch (\Throwable $e) {}
                }
            });
        }
    }

    public function down(): void {
        if (Schema::hasTable('transaction_submissions')) {
            Schema::table('transaction_submissions', function (Blueprint $table) {
                try { $table->dropUnique('trx_sub_terminal_submission_unique'); } catch (\Throwable $e) {}
                // Recreate original unique on submission_uuid for rollback (name inferred)
                try { $table->unique('submission_uuid'); } catch (\Throwable $e) {}
            });
        }
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                try { $table->dropIndex('trx_terminal_submission_lookup'); } catch (\Throwable $e) {}
            });
        }
    }
};

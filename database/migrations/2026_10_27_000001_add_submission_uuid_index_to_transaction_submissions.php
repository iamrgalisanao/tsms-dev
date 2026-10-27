<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index transaction_submissions.submission_uuid on its own.
 *
 * The hot-path legacy idempotency lookup in TransactionIntakeService
 * (app/Services/TransactionIntakeService.php:237) filters by submission_uuid
 * only. The composite unique (terminal_id, submission_uuid) leads with
 * terminal_id and cannot serve it, so every intake did a full scan (~14s on
 * staging's 931k rows).
 *
 * Non-unique by design: 2025_08_13_000010 deliberately made uniqueness per
 * terminal.
 */
return new class extends Migration
{
    private const INDEX = 'transaction_submissions_submission_uuid_index';

    public function up(): void
    {
        if (! Schema::hasTable('transaction_submissions') || ! Schema::hasColumn('transaction_submissions', 'submission_uuid')) {
            return;
        }

        foreach (Schema::getIndexes('transaction_submissions') as $index) {
            if (($index['columns'][0] ?? null) === 'submission_uuid') {
                return; // already served by an index leading with submission_uuid
            }
        }

        // Fail fast on the metadata lock instead of queueing intake queries behind
        // the ALTER during trading. The migration connection is short-lived, so the
        // session variable is not restored.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION lock_wait_timeout = 10');
        }

        Schema::table('transaction_submissions', function (Blueprint $table) {
            $table->index('submission_uuid', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('transaction_submissions')) {
            return;
        }

        $exists = collect(Schema::getIndexes('transaction_submissions'))
            ->contains(fn (array $index) => $index['name'] === self::INDEX);

        if ($exists) {
            Schema::table('transaction_submissions', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};

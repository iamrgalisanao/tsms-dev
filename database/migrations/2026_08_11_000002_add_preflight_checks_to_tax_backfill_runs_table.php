<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Slice 9 (T097) + Slice 10 (T018): holds a small envelope,
     * `{'required_columns' => ..., 'schema_integrity' => ...|null}`, not a
     * single flat result:
     *  - `required_columns` is
     *    App\Services\Backfill\TaxBackfillPreflightChecker::checkRequiredColumns()'s
     *    result — populated for every row, dry-run and apply alike.
     *  - `schema_integrity` is
     *    App\Services\Backfill\TaxBackfillPreflightChecker::check()'s full
     *    structured result (index/FK presence, ON DELETE action,
     *    transaction_pk nullability) — so the run's audit trail is honest
     *    about the exact schema state TaxBackfillRunner::apply() ran
     *    against. Always null for dry-run rows (TaxBackfillRunner::dryRun()
     *    never calls check()), and also null for an apply() row whose
     *    `required_columns` check already failed (check() is short-
     *    circuited in that case).
     */
    public function up(): void
    {
        if (Schema::hasColumn('tax_backfill_runs', 'preflight_checks')) {
            return;
        }

        Schema::table('tax_backfill_runs', function (Blueprint $table) {
            $table->json('preflight_checks')->nullable()->after('failed_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('tax_backfill_runs', 'preflight_checks')) {
            Schema::table('tax_backfill_runs', function (Blueprint $table) {
                $table->dropColumn('preflight_checks');
            });
        }
    }
};

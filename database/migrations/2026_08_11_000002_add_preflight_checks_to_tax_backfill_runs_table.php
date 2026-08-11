<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Slice 9 (T097): holds the full structured result of
     * App\Services\Backfill\TaxBackfillPreflightChecker::check() — not just
     * a pass/fail bit — so the run's audit trail is honest about the exact
     * schema state (index/FK presence, ON DELETE action, transaction_pk
     * nullability) TaxBackfillRunner::apply() ran against. Always null for
     * dry-run rows: TaxBackfillRunner::dryRun() never calls the checker.
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

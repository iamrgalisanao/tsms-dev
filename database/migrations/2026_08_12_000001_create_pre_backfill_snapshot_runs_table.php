<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 002-backfill-transaction-taxes, Slice 15 (T073/T074) — see
     * specs/002-backfill-transaction-taxes/slice-15-pre-backfill-snapshot-brief.md,
     * "Design decisions § 1".
     *
     * One row per invocation of `transactions:snapshot-pre-backfill-aggregates`
     * that actually attempts a capture (dry-run never writes here). Every
     * App\Models\PreBackfillSnapshotRecord row belongs to exactly one run,
     * mirroring the tax_backfill_runs/tax_backfill_records pattern (see
     * 2026_08_11_000000_create_tax_backfill_runs_table.php).
     *
     * `snapshot_type` and `report_contract_version` exist so this window is
     * never "permanently owned" by the first run that happened to capture
     * it: a future, genuinely different snapshot purpose, or a future
     * report-contract-version bump, over the identical window is a distinct
     * row, never a collision or a forced overwrite. Both are fixed class
     * constants on App\Models\PreBackfillSnapshotRun, never CLI-configurable
     * in this slice.
     *
     * Deliberate deviation from a literal reading of the brief's "Unique
     * index" wording: (snapshot_type, window_start, window_end,
     * report_contract_version) is enforced as the run-level idempotency key
     * at the APPLICATION layer only (SnapshotPreBackfillAggregates::resolveRunDecision(),
     * a lookup-then-decide check), not as a database UNIQUE constraint. A
     * hard DB-level unique constraint on this tuple is provably incompatible
     * with the brief's own mandatory --force behavior (Design decision 5):
     * "--force given: create a NEW run row... the prior completed run and
     * its records are never touched, updated, or deleted" necessarily
     * produces two rows sharing the identical 4-column tuple, which a
     * literal UNIQUE constraint would reject outright. A plain (non-unique)
     * index is kept below purely for lookup performance.
     */
    public function up(): void
    {
        if (Schema::hasTable('pre_backfill_snapshot_runs')) {
            return;
        }

        Schema::create('pre_backfill_snapshot_runs', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_type');
            $table->string('report_contract_version');
            $table->timestamp('window_start');
            $table->timestamp('window_end');
            $table->string('status')->default('running')->index();
            $table->unsignedInteger('tenant_count')->default(0);
            $table->unsignedInteger('month_count')->default(0);
            // True when this run was created via --force despite a prior
            // completed run already existing for the identical
            // (snapshot_type, window_start, window_end, report_contract_version)
            // key. The prior completed run and its records are never
            // touched, updated, or deleted — old baselines are permanent
            // evidence.
            $table->boolean('forced')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Lookup-performance index for the run-level idempotency key
            // (not window_start/window_end alone) — see Design decision 5
            // of the slice-15 brief, and the docblock above for why this is
            // NOT a database UNIQUE constraint.
            $table->index(
                ['snapshot_type', 'window_start', 'window_end', 'report_contract_version'],
                'pbsr_snapshot_window_version_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pre_backfill_snapshot_runs');
    }
};

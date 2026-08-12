<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 002-backfill-transaction-taxes, Slice 16 (T075/T099) — see
     * specs/002-backfill-transaction-taxes/slice-16-integrity-evidence-capture-brief.md,
     * "Design decisions § 1".
     *
     * One row per invocation of `transactions:capture-integrity-evidence`
     * that persists (--apply). Independent of, and NOT coupled to,
     * pre_backfill_snapshot_runs/pre_backfill_snapshot_records (Slice 15) —
     * this table captures a different kind of evidence (the corrected T062
     * duplicate-check baseline plus the verbatim `txn:pk-integrity` report),
     * not a rendered-aggregate baseline.
     *
     * No uniqueness constraint on (window_start, window_end, phase) —
     * multiple `pre_run` captures for the same window are allowed and
     * expected (e.g. one during rehearsal, a fresher one taken immediately
     * before the real live run). T076, later, is responsible for choosing
     * the most recent `pre_run` capture as its comparison baseline.
     */
    public function up(): void
    {
        if (Schema::hasTable('pre_run_integrity_captures')) {
            return;
        }

        Schema::create('pre_run_integrity_captures', function (Blueprint $table) {
            $table->id();
            // The --from/--to this capture is *for* — metadata only. The
            // duplicate-check query itself is whole-table, not scoped to
            // this window (Design decision 2 of the brief).
            $table->timestamp('window_start');
            $table->timestamp('window_end');
            // 'pre_run' | 'post_run' — this slice only ever writes
            // 'pre_run'; the column exists now so T076 doesn't need a
            // schema change later to record its own post-run capture
            // through the same mechanism.
            $table->string('phase')->index();
            // {total_duplicate_groups, total_duplicate_rows, sample} from
            // the corrected T062 query (WHERE transaction_pk IS NOT NULL
            // before GROUP BY) — sample capped defensively, not because a
            // large result is expected.
            $table->json('duplicate_check_summary');
            // The verbatim console output of `php artisan txn:pk-integrity`
            // (T099) — never parsed/restructured.
            $table->text('integrity_report');
            $table->timestamp('captured_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pre_run_integrity_captures');
    }
};

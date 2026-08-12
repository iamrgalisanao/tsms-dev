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
     * One row per (run, tenant, reporting month) actually captured. Never
     * UPDATE a row here, only INSERT — that, plus the unique constraint
     * below, is what makes FR-012a's source-label pinning possible without
     * any comparison logic in this slice (Design decision 2).
     *
     * `rendered_result` is the verbatim
     * App\Services\Reports\SalesReportResult::toArray() output for that
     * (tenant, reporting month) — the whole rendered report, not a
     * cherry-picked field (Design decision 3).
     */
    public function up(): void
    {
        if (Schema::hasTable('pre_backfill_snapshot_records')) {
            return;
        }

        Schema::create('pre_backfill_snapshot_records', function (Blueprint $table) {
            $table->id();
            // Deliberately no cascade delete: a run can't be deleted while
            // it still has records, mirroring tax_backfill_records.run_id's
            // own reasoning — this feature's ethos is "never silently
            // destroy evidence."
            $table->foreignId('run_id')->constrained('pre_backfill_snapshot_runs');
            // Denormalized, not a hard FK to `tenants` — mirrors
            // tax_backfill_records.tenant_id, which is likewise a plain
            // indexed column rather than a foreign key.
            $table->unsignedBigInteger('tenant_id')->index();
            // Integers, not a date — a reporting month is not a calendar
            // date (Design decision 1).
            $table->unsignedSmallInteger('reporting_year');
            $table->unsignedTinyInteger('reporting_month');
            // 'daily_transaction_summaries' | 'raw_transactions' — pulled
            // directly from SalesReportResult::$source, never re-derived
            // (FR-012a).
            $table->string('source');
            $table->json('rendered_result');
            $table->timestamp('captured_at');
            $table->timestamps();

            // Idempotency and resumability both rely on this constraint
            // (Design decision 5): re-invoking the command skips any
            // (tenant, month) pair that already has a record here.
            $table->unique(
                ['run_id', 'tenant_id', 'reporting_year', 'reporting_month'],
                'pbsr_run_tenant_year_month_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pre_backfill_snapshot_records');
    }
};

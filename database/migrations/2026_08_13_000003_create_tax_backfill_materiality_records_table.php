<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 002-backfill-transaction-taxes, Slice 19 (T047/Command 3) — see
     * specs/002-backfill-transaction-taxes/slice-19-materiality-brief.md.
     *
     * One row per (run, tenant, reporting month) actually compared. Never
     * UPDATE a row here, only INSERT (mirrors pre_backfill_snapshot_records).
     * `before_source`/`before` totals are not duplicated here — they are
     * read via a join to pre_backfill_snapshot_records on
     * (snapshot_run_id, tenant_id, reporting_year, reporting_month), which
     * are themselves guaranteed immutable post-capture (FR-012a).
     * `after_rendered_result` is the verbatim
     * App\Services\Reports\SalesReportResult::toArray() output, not just
     * the other_tax field, for SC-003 drift-checking and audit fidelity.
     */
    public function up(): void
    {
        if (Schema::hasTable('tax_backfill_materiality_records')) {
            return;
        }

        Schema::create('tax_backfill_materiality_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('tax_backfill_materiality_runs');
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedSmallInteger('reporting_year');
            $table->unsignedTinyInteger('reporting_month');
            $table->string('before_source');
            $table->string('after_source');
            $table->json('after_rendered_result');
            // 'compared' | 'source_mismatch' (FR-012a) — the latter leaves
            // every column below null and is excluded from summary totals.
            $table->string('comparison_status');
            $table->decimal('other_tax_before', 15, 2)->nullable();
            $table->decimal('other_tax_after', 15, 2)->nullable();
            $table->decimal('other_tax_delta_amount', 15, 2)->nullable();
            // Null (not zero, not infinity) when before is zero — see
            // Design decision 4 of the brief.
            $table->decimal('other_tax_delta_percent', 10, 4)->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(
                ['run_id', 'tenant_id', 'reporting_year', 'reporting_month'],
                'tbmr_run_tenant_year_month_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_backfill_materiality_records');
    }
};

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
     * One row per invocation of `transactions:tax-backfill-materiality`
     * that actually attempts a capture. `snapshot_run_id` is not nullable —
     * a materiality run always has exactly one before-baseline. No
     * threshold columns here: FR-009a's threshold evaluation is a read-time
     * concern (see the brief), not part of the immutable captured evidence.
     */
    public function up(): void
    {
        if (Schema::hasTable('tax_backfill_materiality_runs')) {
            return;
        }

        Schema::create('tax_backfill_materiality_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_run_id')->constrained('pre_backfill_snapshot_runs');
            $table->string('report_contract_version');
            $table->string('status')->default('running')->index();
            $table->unsignedInteger('tenant_count')->default(0);
            $table->unsignedInteger('month_count')->default(0);
            $table->boolean('forced')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_backfill_materiality_runs');
    }
};

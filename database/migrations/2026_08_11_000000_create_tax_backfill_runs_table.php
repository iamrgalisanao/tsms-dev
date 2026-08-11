<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per invocation of the (not-yet-built) TaxBackfillRunner. Every
     * `tax_backfill_records` row belongs to exactly one run. See
     * specs/002-backfill-transaction-taxes/data-model.md, "New entity:
     * backfill run/progress record".
     */
    public function up(): void
    {
        if (Schema::hasTable('tax_backfill_runs')) {
            return;
        }

        Schema::create('tax_backfill_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('window_start');
            $table->timestamp('window_end');
            $table->string('mode');
            $table->unsignedInteger('scanned_count')->default(0);
            $table->unsignedInteger('reconstructed_count')->default(0);
            $table->unsignedInteger('skipped_existing_count')->default(0);
            $table->unsignedInteger('quarantined_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status')->default('running')->index();
            $table->string('operator')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_backfill_runs');
    }
};

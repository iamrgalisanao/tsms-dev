<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 002-backfill-transaction-taxes, Slice 12 (T066) — Stage 1 of 3 for the
     * orphan archive/reconcile/delete pipeline. See
     * specs/002-backfill-transaction-taxes/slice-12-orphan-archive-brief.md.
     *
     * This table is the durable, byte-faithful copy of every orphaned
     * (`transaction_pk IS NULL`) `transaction_taxes` row, written by
     * App\Services\Backfill\OrphanTaxArchiver before any later stage is ever
     * permitted to delete the source row. `reconciled_status`/`reason_code`
     * exist now purely so Stage 2 (reconcile) doesn't need its own
     * migration — every row Stage 1 (this slice) writes leaves both NULL.
     *
     * Guarded with Schema::hasTable(), per this repo's own prior duplicate-
     * migration collision on `ingestion_quarantine` (see
     * database/migrations/2025_11_13_000000_create_ingestion_quarantine_table.php
     * and .../2026_06_14_000001_create_ingestion_quarantine_table.php — both
     * exist, and both now guard the same way). This is a brand-new table
     * with no such collision yet, but guarding from its very first migration
     * avoids ever repeating that mistake here.
     */
    public function up(): void
    {
        if (Schema::hasTable('transaction_taxes_orphan_archive')) {
            return;
        }

        Schema::create('transaction_taxes_orphan_archive', function (Blueprint $table) {
            $table->id();

            // The source transaction_taxes.id. Unique for two reasons at
            // once: restoration fidelity (FR-013 — the original row's
            // identity must be reproducible) AND the idempotency mechanism
            // OrphanTaxArchiver::archive() relies on — insertOrIgnore()
            // keyed on this constraint makes re-running the archiver over an
            // already-archived row a silent, DB-level no-op, with no
            // separate resume-cursor needed.
            $table->unsignedBigInteger('original_id')->unique();

            // Always NULL for an orphan, by definition (that's what makes it
            // an orphan) — preserved verbatim anyway for schema-fidelity,
            // not because it carries meaning on this table.
            $table->unsignedBigInteger('transaction_pk')->nullable();

            $table->string('tax_type', 20);
            $table->decimal('amount', 15, 2);

            // The ORIGINAL row's own created_at/updated_at — never this
            // archive operation's timestamp (see archived_at below for
            // that). Nullable to mirror transaction_taxes.updated_at's own
            // nullability (a standard $table->timestamps() column, per
            // database/migrations/2025_07_04_000023_create_transaction_taxes_table.php).
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            // One UUID generated per ArchiveOrphanTaxRows --apply
            // invocation — deliberately not a foreign key to a dedicated
            // "runs" table; a plain string column is sufficient for this
            // slice's stated purpose.
            $table->string('archive_run_id');

            // When THIS archive row was written — distinct from the
            // original row's own created_at/updated_at above.
            $table->timestamp('archived_at');

            // Stage 2 (reconcile) populates these later. This slice (Stage
            // 1, archive-only) writes NULL into both for every row it
            // archives, with no exceptions.
            $table->string('reconciled_status')->nullable();
            $table->string('reason_code')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately a guarded no-op, NOT Schema::dropIfExists(). Once a later
     * stage (Stage 3, T070/T070a) deletes the live orphan rows from
     * `transaction_taxes`, this archive table becomes the ONLY durable
     * record that those rows ever existed. A migration rollback must never
     * be able to destroy the one surviving copy of already-deleted data —
     * so down() intentionally does nothing, ever, no matter how many times
     * it is invoked.
     */
    public function down(): void
    {
        // Intentional no-op — see docblock above. Do not add
        // Schema::dropIfExists() here.
    }
};

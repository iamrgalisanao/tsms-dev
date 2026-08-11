<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per transaction scanned by a tax-backfill run, whether or not
     * it ended up written. See
     * specs/002-backfill-transaction-taxes/data-model.md, "New entity:
     * backfill run/progress record" (row-level table).
     *
     * IMPORTANT: writes to this table MUST go through App\Models\TaxBackfillRecord,
     * never a raw DB::table() insert/update — the outcome/reason-code
     * invariant is enforced only at that model's boot() layer, not by a DB
     * constraint here.
     */
    public function up(): void
    {
        if (Schema::hasTable('tax_backfill_records')) {
            return;
        }

        Schema::create('tax_backfill_records', function (Blueprint $table) {
            $table->id();
            // Deliberately no cascade delete: a run can't be deleted while
            // it still has records, consistent with transaction_pk's own
            // RESTRICT reasoning below — this feature's ethos elsewhere
            // (FR-013's archive-before-delete) is "never silently destroy
            // evidence," and cascading here would do exactly that from the
            // parent side.
            $table->foreignId('run_id')->constrained('tax_backfill_runs');
            // Deliberately no cascade delete (unlike legacy transaction_taxes.transaction_pk,
            // which cascades): this audit trail should outlive, and block
            // deletion of, the transaction it corrects — not disappear
            // silently alongside it.
            $table->unsignedBigInteger('transaction_pk')->index();
            $table->foreign('transaction_pk')->references('id')->on('transactions');
            // Denormalized from transactions.tenant_id so per-tenant
            // materiality rollups (FR-009a) don't need to re-join.
            $table->unsignedBigInteger('tenant_id')->index();
            $table->json('reconstructed_tax_rows')->nullable();
            // Confirms "before" was empty (no linked transaction_taxes rows
            // pre-backfill) — makes the no-overwrite guarantee auditable
            // without storing a full snapshot.
            $table->boolean('had_linked_rows_before')->default(false);
            $table->string('outcome')->index();
            // Required (app-layer, not a DB constraint — T012) when outcome
            // is `quarantined` or `failed`. See App\Services\Backfill\TaxBackfillOutcome
            // and App\Services\Backfill\TaxBackfillReasonCode.
            $table->string('reason_code')->nullable();
            // Placeholder link to the orphan-archive row(s)/reconciliation
            // result this correction corresponds to (FR-013/FR-014). Not a
            // hard FK: the orphan-archive table doesn't exist yet (T066+).
            $table->json('archive_reference')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_backfill_records');
    }
};

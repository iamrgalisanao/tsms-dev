<?php

declare(strict_types=1);

namespace App\Services\Backfill;

use Illuminate\Support\Facades\Schema;

/**
 * 002-backfill-transaction-taxes, Slice 9 (T097) + Slice 10 (T018).
 *
 * Two independent, differently-scoped schema pre-flight checks, both
 * recorded (as a small envelope — see TaxBackfillRunner's own docblock for
 * the exact shape) on TaxBackfillRun::preflight_checks:
 *
 *  - check() (T097, Slice 9): index/FK/nullability on transaction_taxes.
 *    apply()-only, unchanged by Slice 10 — see this method's own docblock
 *    below.
 *  - checkRequiredColumns() (T018, Slice 10): column *existence* across
 *    `transactions` and `transaction_taxes` — the columns this feature's
 *    read path (TaxReconstructionService) and write path
 *    (TaxBackfillRunner::insertTaxRows()) actually depend on. Run from BOTH
 *    dryRun() and apply(), because a missing column breaks dry-run too (just
 *    with a confusing raw SQL error instead of a clean pre-flight failure).
 *
 * These two methods are deliberately independent — different callers,
 * different gating scope — and neither is merged into the other. See
 * specs/002-backfill-transaction-taxes/slice-9-preflight-brief.md and
 * specs/002-backfill-transaction-taxes/slice-10-t018-column-preflight-brief.md.
 *
 * Per the Slice 9 brief's fact table, only two of check()'s four observed
 * facts gate the run:
 *  - `index_present` (idx_tx_taxes_pk on transaction_taxes.transaction_pk):
 *    gates. Without it, chunked scans/deletes against a multi-million-row
 *    table are unsafe by construction.
 *  - `fk_present` (fk_tx_taxes_pk, transaction_pk -> transactions.id): gates.
 *    Referential-integrity backstop for the exact defect class this feature
 *    exists to fix.
 *  - `fk_on_delete_action`: recorded only. Confirmed by this class at
 *    runtime (not merely trusted from reading the migration once) to be
 *    `cascade` today.
 *  - `transaction_pk_nullable`: recorded only. This feature's entire premise
 *    is that the column IS currently nullable — that's the defect condition,
 *    not something to fail the run on.
 *
 * The exact Schema::getForeignKeys()/getColumns() return shapes below were
 * verified empirically (via tinker against the real transaction_taxes table
 * on this Laravel version) rather than assumed:
 *
 * Schema::getForeignKeys('transaction_taxes') returns a list of arrays
 * shaped like:
 *   ['name' => 'fk_tx_taxes_pk', 'columns' => ['transaction_pk'],
 *    'foreign_schema' => '...', 'foreign_table' => 'transactions',
 *    'foreign_columns' => ['id'], 'on_update' => 'no action',
 *    'on_delete' => 'cascade']
 *
 * Schema::getColumns('transaction_taxes') returns a list of arrays shaped
 * like (the transaction_pk entry):
 *   ['name' => 'transaction_pk', 'type_name' => 'bigint',
 *    'type' => 'bigint unsigned', 'collation' => null, 'nullable' => true,
 *    'default' => null, 'auto_increment' => false, 'comment' => null,
 *    'generation' => null]
 */
class TaxBackfillPreflightChecker
{
    protected const TABLE = 'transaction_taxes';

    protected const INDEX_NAME = 'idx_tx_taxes_pk';

    protected const FK_NAME = 'fk_tx_taxes_pk';

    protected const COLUMN_NAME = 'transaction_pk';

    /**
     * Slice 10 (T018): columns this feature's read/write paths depend on in
     * `transactions` — a table this feature reads from but does not own the
     * schema of. Most (original_payload, vatable_sales, vat_amount,
     * sc_vat_exempt_sales) are read directly by
     * TaxReconstructionService::reconstructTaxRows()/crossCheck(); `id` and
     * `created_at` are read/written via TaxBackfillRunner::insertTaxRows();
     * `tenant_id` is included defensively (recorded on
     * TaxBackfillRecord/used for --tenant filtering elsewhere in this
     * feature) rather than because either of those two classes reads it
     * directly.
     *
     * @var list<string>
     */
    protected const REQUIRED_TRANSACTIONS_COLUMNS = [
        'id',
        'tenant_id',
        'created_at',
        'original_payload',
        'vatable_sales',
        'vat_amount',
        'sc_vat_exempt_sales',
    ];

    /**
     * Slice 10 (T018): columns this feature's read/write paths depend on in
     * `transaction_taxes`. `transaction_pk`, `tax_type`, and `amount` are
     * written directly by TaxBackfillRunner::insertTaxRows() and read back
     * by TaxReconstructionService::crossCheck()/the verification oracle;
     * `created_at` is written by insertTaxRows() (the parent transaction's
     * own created_at, per its docblock); `id` is included defensively (an
     * ordinary primary key expected on any real table, not one this
     * feature's own code reads by name). `updated_at` is deliberately
     * excluded — it's already conditionally checked via Schema::hasColumn()
     * inside insertTaxRows() itself, so duplicating it here would be
     * redundant.
     *
     * @var list<string>
     */
    protected const REQUIRED_TRANSACTION_TAXES_COLUMNS = [
        'id',
        'transaction_pk',
        'tax_type',
        'amount',
        'created_at',
    ];

    /**
     * @return array{index_present: bool, fk_present: bool, fk_on_delete_action: string|null, transaction_pk_nullable: bool, passed: bool}
     */
    public function check(): array
    {
        $indexPresent = Schema::hasIndex(self::TABLE, self::INDEX_NAME);

        $foreignKey = collect(Schema::getForeignKeys(self::TABLE))
            ->first(fn (array $fk) => $fk['name'] === self::FK_NAME);

        $fkPresent = $foreignKey !== null;
        $fkOnDeleteAction = $foreignKey['on_delete'] ?? null;

        $column = collect(Schema::getColumns(self::TABLE))
            ->first(fn (array $col) => $col['name'] === self::COLUMN_NAME);

        // Defensive default only — the column is expected to always exist;
        // this doesn't gate the run either way (see class docblock), so a
        // conservative `false` here simply avoids fabricating a "nullable"
        // fact for a column this class couldn't actually find.
        $transactionPkNullable = (bool) ($column['nullable'] ?? false);

        return [
            'index_present' => $indexPresent,
            'fk_present' => $fkPresent,
            'fk_on_delete_action' => $fkOnDeleteAction,
            'transaction_pk_nullable' => $transactionPkNullable,
            'passed' => $indexPresent && $fkPresent,
        ];
    }

    /**
     * Slice 10 (T018): existence check (not full column introspection —
     * Schema::hasColumn() is the simplest, most direct tool for a plain
     * existence question) for every column named in
     * self::REQUIRED_TRANSACTIONS_COLUMNS / self::REQUIRED_TRANSACTION_TAXES_COLUMNS.
     * Independent of check() — different caller (both dryRun() and apply(),
     * not apply()-only), different gating rule, never merged into one
     * combined method.
     *
     * Deliberately does not check `tax_backfill_runs`/`tax_backfill_records`
     * — this feature's own migrated tables. If those are missing, the very
     * first TaxBackfillRun::create() call already fails immediately and
     * unambiguously; that is not the confusing case this check exists to
     * prevent (a column silently missing on a table this feature doesn't
     * own the schema of).
     *
     * @return array{missing: list<string>, passed: bool}
     */
    public function checkRequiredColumns(): array
    {
        $missing = [];

        foreach (self::REQUIRED_TRANSACTIONS_COLUMNS as $column) {
            if (! Schema::hasColumn('transactions', $column)) {
                $missing[] = "transactions.{$column}";
            }
        }

        foreach (self::REQUIRED_TRANSACTION_TAXES_COLUMNS as $column) {
            if (! Schema::hasColumn('transaction_taxes', $column)) {
                $missing[] = "transaction_taxes.{$column}";
            }
        }

        return [
            'missing' => $missing,
            'passed' => $missing === [],
        ];
    }
}

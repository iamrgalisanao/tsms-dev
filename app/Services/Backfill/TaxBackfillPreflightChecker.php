<?php

declare(strict_types=1);

namespace App\Services\Backfill;

use Illuminate\Support\Facades\Schema;

/**
 * 002-backfill-transaction-taxes, Slice 9 (T097).
 *
 * Schema pre-flight check run once, at the very start of
 * TaxBackfillRunner::apply() (never dryRun()), before any transaction is
 * scanned — see specs/002-backfill-transaction-taxes/slice-9-preflight-brief.md
 * for the full scope contract.
 *
 * Per the brief's fact table, only two of the four observed facts gate the
 * run:
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
}

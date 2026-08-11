<?php

declare(strict_types=1);

namespace App\Services\Backfill;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

/**
 * Pure reconstruction logic for the transaction_taxes backfill.
 *
 * Given a transaction whose original_payload survived a defect that silently
 * dropped its transaction_taxes rows, this service:
 *   1. Reconstructs the candidate tax rows the payload originally carried.
 *   2. Cross-checks those candidates against the vatable_sales / vat_amount /
 *      sc_vat_exempt_sales columns that were populated (correctly, by a
 *      separate code path) at ingestion time, as an independent consistency
 *      signal for a later slice to decide whether to quarantine.
 *
 * This class performs no database writes and has no side effects other than
 * Log::warning() for malformed rows it skips. It intentionally does not call
 * or extract TransactionIngestService::insertTaxes() (research.md R5/Q8/N10:
 * the live insert path's semantics differ from what this backfill needs), and
 * it does not perform tax_type alias normalization beyond the narrow alias
 * sets required to replay applyTaxColumns()'s historical behavior — broader
 * alias handling belongs to the separate "Track B" workstream.
 */
class TaxReconstructionService
{
    /**
     * Reconstruct the candidate transaction_taxes rows implied by a
     * transaction's original_payload.
     *
     * Mirrors the malformed-row guard shape of
     * TransactionIngestService::insertTaxes() (app/Services/TransactionIngestService.php:435),
     * but preserves tax_type verbatim as submitted (no uppercasing/trimming/
     * alias normalization) and never touches the database.
     *
     * Handles gracefully (returns []): missing original_payload, malformed/
     * non-JSON original_payload, and a payload with no `taxes` key. A payload
     * with more than 4 tax rows is not truncated — every valid row is kept.
     *
     * @return array<int, array{tax_type: mixed, amount: mixed}>
     */
    public function reconstructTaxRows(Transaction $transaction): array
    {
        $payload = $this->decodePayload($transaction);

        if (! isset($payload['taxes']) || ! is_array($payload['taxes'])) {
            return [];
        }

        $rows = [];

        foreach ($payload['taxes'] as $tax) {
            if (! is_array($tax) || ! isset($tax['tax_type'], $tax['amount']) || $tax['tax_type'] === '' || $tax['amount'] === null) {
                Log::warning('TaxReconstructionService: Skipping malformed tax row', [
                    'transaction_id' => $transaction->id,
                    'row' => $tax,
                ]);

                continue;
            }

            $rows[] = [
                'tax_type' => $tax['tax_type'],
                'amount' => $tax['amount'],
            ];
        }

        return $rows;
    }

    /**
     * Cross-check reconstructed tax rows against the transaction's stored
     * vatable_sales / vat_amount / sc_vat_exempt_sales columns.
     *
     * Those columns were populated at ingestion time by
     * TransactionIngestService::applyTaxColumns() (app/Services/TransactionIngestService.php:363-377),
     * which iterates tax rows in order and *overwrites* (last-wins, not sums)
     * the matching column whenever a row's uppercased/trimmed tax_type falls
     * into one of three narrow alias sets. This method replays that exact
     * logic against the reconstructed candidates and compares the result to
     * what's actually stored, so payloads that legitimately carry two rows of
     * the same type don't produce a false-positive mismatch (T096).
     *
     * This is purely a read-only signal for a later slice — it writes nothing.
     *
     * @param  array<int, array{tax_type: mixed, amount: mixed}>  $reconstructedRows
     * @return array<int, string> Names of columns that disagree; empty = full match.
     */
    public function crossCheck(Transaction $transaction, array $reconstructedRows): array
    {
        $expected = $this->applyLastWinsAliasLogic($reconstructedRows);

        $actual = [
            'vatable_sales' => $transaction->vatable_sales,
            'sc_vat_exempt_sales' => $transaction->sc_vat_exempt_sales,
            'vat_amount' => $transaction->vat_amount,
        ];

        $mismatches = [];

        foreach ($actual as $column => $storedValue) {
            if (! $this->amountsMatch($expected[$column] ?? null, $storedValue)) {
                $mismatches[] = $column;
            }
        }

        return $mismatches;
    }

    /**
     * Decode original_payload JSON. Returns null for a missing value or a
     * value that is not valid JSON / does not decode to an array.
     *
     * original_payload has no Eloquent cast, so a DB-hydrated Transaction
     * always yields a raw string (or null) here — never a pre-decoded array —
     * consistent with every other consumer of this column in the codebase
     * (e.g. JobProcessingService, TransactionLogController), which likewise
     * json_decode() it directly rather than branching on is_array().
     *
     * @return array<string, mixed>|null
     */
    protected function decodePayload(Transaction $transaction): ?array
    {
        $raw = $transaction->original_payload;

        if (empty($raw) || ! is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            Log::warning('TaxReconstructionService: Malformed original_payload JSON', [
                'transaction_id' => $transaction->id,
                'json_error' => json_last_error_msg(),
            ]);

            return null;
        }

        return $decoded;
    }

    /**
     * Mirrors applyTaxColumns()'s alias-matching and last-wins overwrite
     * logic: iterates tax rows in order, uppercasing/trimming tax_type, and
     * overwrites (last-wins) the matching column for each narrow alias set.
     * Rows that match no alias leave the corresponding key unset here, as
     * applyTaxColumns() would leave the normalized column untouched.
     *
     * This omits applyTaxColumns()'s Schema::hasColumn() guards per column —
     * those exist to protect a live write against a not-yet-migrated
     * transactions table, which is irrelevant here since this method never
     * writes to the transactions table.
     *
     * @param  array<int, array{tax_type: mixed, amount: mixed}>  $taxes
     * @return array{vatable_sales?: float, sc_vat_exempt_sales?: float, vat_amount?: float}
     */
    protected function applyLastWinsAliasLogic(array $taxes): array
    {
        $result = [];

        foreach ($taxes as $tax) {
            $type = strtoupper(trim((string) ($tax['tax_type'] ?? '')));
            $amount = (float) ($tax['amount'] ?? 0.0);

            if ($type === 'VATABLE_SALES' || $type === 'VATABLE') {
                $result['vatable_sales'] = $amount;
            } elseif (in_array($type, ['SC_VAT_EXEMPT_SALES', 'VAT_EXEMPT_SALES', 'VATEXEMPT_SALES'], true)) {
                $result['sc_vat_exempt_sales'] = $amount;
            } elseif ($type === 'VAT' || $type === 'VAT_AMOUNT') {
                $result['vat_amount'] = $amount;
            }
        }

        return $result;
    }

    /**
     * Compares monetary values with 2-decimal rounding to absorb float
     * precision noise; unlike Transaction::validateVatAmount()'s 0.10
     * tolerance (which exists for a different reason — tolerating
     * inconsistent VAT rounding across varied POS implementations), this
     * uses exact equality after rounding, since both values compared here
     * derive from the same decimal-cast source rather than two independently
     * computed figures.
     *
     * An unset expected value (no alias matched among the reconstructed rows)
     * is treated the same as applyTaxColumns() leaving the column untouched —
     * i.e. compared as 0.0, since that's what a never-touched money column
     * resolves to for comparison purposes here.
     */
    protected function amountsMatch(?float $expected, mixed $stored): bool
    {
        $expected ??= 0.0;
        $storedFloat = $stored === null ? 0.0 : (float) $stored;

        return round($expected, 2) === round($storedFloat, 2);
    }
}

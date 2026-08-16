<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes, T081 — see tasks.md.
 *
 * The cheapest permanent guard against the exact defect class this whole
 * feature exists to fix: `TransactionTax::$fillable` is
 * `['transaction_pk', 'tax_type', 'amount']` (app/Models/TransactionTax.php)
 * — a `TransactionTax::create()` call passing a `transaction_id` key instead
 * of `transaction_pk` has that key silently dropped by mass-assignment
 * protection, leaving `transaction_pk` unset. On the current schema
 * (`transaction_pk` is `NOT NULL` since migration
 * `2025_08_13_000013_enforce_transaction_pk_children.php`) this throws a
 * `QueryException` rather than silently persisting an orphaned row — but
 * this guard exists precisely so nobody has to rely on that constraint
 * remaining in place, or discover the bug via a production 500 instead of a
 * failing test.
 *
 * This test scans every PHP file under app/ (not a hand-picked list of call
 * sites) so a future new call site is caught automatically, mirroring
 * OrphanTaxReconcilerTest's own structural source-scanning pattern
 * (tests/Feature/Services/Backfill/OrphanTaxReconcilerTest.php).
 *
 * Named with "Backfill" for this feature's `--filter=Backfill` convention,
 * even though the guarded call sites
 * (App\Http\Controllers\API\V1\TransactionController::processAdjustmentsAndTaxes()/
 * storeOfficialLegacy()) are outside the Backfill subsystem itself and are
 * currently unrouted/unreachable (processOfficialSubmission() has no route
 * binding anywhere in routes/*.php; storeOfficialLegacy()'s only caller,
 * TSMSTransactionRequest, is never instantiated per tasks.md T088c) — this
 * guard exists so reviving either dead path can never silently reintroduce
 * the defect.
 */
class BackfillTransactionTaxCreateGuardTest extends TestCase
{
    public function test_no_transaction_tax_create_call_passes_a_transaction_id_key(): void
    {
        $violations = [];

        $finder = (new Finder)->files()->in(app_path())->name('*.php');

        foreach ($finder as $file) {
            $source = $file->getContents();

            if (! str_contains($source, 'TransactionTax::create(')) {
                continue;
            }

            foreach ($this->extractCreateCallArgs($source) as $callArgs) {
                if (preg_match('/[\'"]transaction_id[\'"]\s*=>/', $callArgs)) {
                    $violations[] = $file->getRelativePathname();
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'TransactionTax::create() must be called with a transaction_pk key, never transaction_id -- '.
            'TransactionTax::$fillable does not include transaction_id, so that key is silently dropped, '.
            'leaving transaction_pk NULL. Violating file(s): '.implode(', ', $violations)
        );
    }

    public function test_extract_call_args_is_not_truncated_by_an_unbalanced_paren_inside_a_string_literal(): void
    {
        // The stray, unmatched ')' inside the note string is the key case:
        // a naive char-by-char depth counter that doesn't skip string
        // contents would treat it as closing the call's own opening '(' one
        // character too early, truncating the capture before it ever
        // reaches transaction_id => below -- exactly the false-negative
        // this test guards against.
        $source = <<<'PHP'
            <?php
            $foo = TransactionTax::create([
                'note' => 'see ticket TSMS-123)',
                'transaction_id' => $model->transaction_id,
                'tax_type' => $tax['tax_type'],
            ]);
            PHP;

        $calls = $this->extractCreateCallArgs($source);

        $this->assertCount(1, $calls);
        $this->assertStringContainsString('transaction_id', $calls[0]);
    }

    /**
     * Extracts the raw argument text of every `TransactionTax::create(...)`
     * call in $source, by bracket-depth matching from each call's opening
     * parenthesis to its balanced close -- robust to nested arrays/brackets
     * inside the call, unlike a single non-greedy regex. Skips over the
     * contents of '...'/"..." string literals while counting depth, so a
     * stray `)` inside a quoted value (e.g. a comment string) can't
     * truncate the capture early and hide a later `transaction_id =>` key
     * -- the exact class of false negative this test exists to avoid.
     *
     * @return array<int, string>
     */
    private function extractCreateCallArgs(string $source): array
    {
        $calls = [];
        $needle = 'TransactionTax::create(';
        $offset = 0;

        while (($pos = strpos($source, $needle, $offset)) !== false) {
            $start = $pos + strlen($needle);
            $depth = 1;
            $i = $start;
            $inString = null;

            while ($i < strlen($source) && $depth > 0) {
                $char = $source[$i];

                if ($inString !== null) {
                    if ($char === '\\') {
                        $i++;
                    } elseif ($char === $inString) {
                        $inString = null;
                    }
                } elseif ($char === '\'' || $char === '"') {
                    $inString = $char;
                } elseif ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                }
                $i++;
            }

            $calls[] = substr($source, $start, $i - $start - 1);
            $offset = $i;
        }

        return $calls;
    }
}

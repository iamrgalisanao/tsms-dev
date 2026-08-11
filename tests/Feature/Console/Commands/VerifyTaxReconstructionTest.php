<?php

namespace Tests\Feature\Console\Commands;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionTax;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes, Slice 5 (T023-T025).
 *
 * Covers `transactions:verify-tax-reconstruction` — the feature's primary
 * safety gate. It replays TaxReconstructionService against transactions that
 * already have real, correct transaction_taxes rows and asserts reconstruction
 * reproduces them exactly (multiset comparison of tax_type/amount).
 *
 * Known test-isolation issue (tests/TestCase.php:38 breaks RefreshDatabase —
 * see tasks.md Backlog): rows persist across test methods within the same
 * run. This command's --from is an open-ended floor with no --to, so each
 * test below uses its own far-future, strictly-increasing-by-declaration-
 * order calendar window (2031, 2032, 2033, ...) — no other test file in this
 * suite uses that date range, and because later-declared tests run after
 * earlier ones, an earlier test's fixtures can never be dated inside a later
 * test's window and vice versa. Every count-sensitive assertion is
 * additionally scoped to this test's own fixture ids rather than relying on
 * global/table-wide state.
 *
 * Dates are deliberately kept below 2038: `transactions.created_at` is a SQL
 * TIMESTAMP column (32-bit range, max 2038-01-19), and this connection runs
 * with `strict => false` (config/database.php), so an out-of-range insert is
 * silently clamped rather than rejected — it would corrupt the very fixture
 * these tests depend on instead of raising an error. Only the empty-pool
 * test's --from value (never inserted, only queried) safely uses a value
 * beyond that range.
 */
class VerifyTaxReconstructionTest extends TestCase
{
    private function makeTransaction(array $attributes = []): Transaction
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        return Transaction::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
        ], $attributes));
    }

    private function linkTax(Transaction $transaction, string $taxType, float $amount): TransactionTax
    {
        return TransactionTax::create([
            'transaction_pk' => $transaction->id,
            'tax_type' => $taxType,
            'amount' => $amount,
        ]);
    }

    private function divergenceFor(array $decoded, int $transactionId): ?array
    {
        foreach ($decoded['divergences'] as $divergence) {
            if ($divergence['transaction_id'] === $transactionId) {
                return $divergence;
            }
        }

        return null;
    }

    /**
     * T025 — the single most important test in this slice: a verifier that
     * cannot be proven to fail is worthless as a safety gate. The payload
     * says VAT: 120.00; the persisted (ground-truth) row deliberately says
     * VAT: 999.00. The divergence must be reported with the transaction id
     * and a description that correctly identifies what's wrong.
     */
    public function test_reports_divergence_for_deliberately_mismatched_actual_tax_rows(): void
    {
        $transaction = $this->makeTransaction([
            'created_at' => '2031-01-01 08:00:00',
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 120.00]]]),
        ]);
        $this->linkTax($transaction, 'VAT', 999.00);

        $exitCode = Artisan::call('transactions:verify-tax-reconstruction', [
            '--from' => '2031-01-01',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertNotSame(0, $exitCode);
        $this->assertGreaterThan(0, $decoded['divergence_count']);

        $divergence = $this->divergenceFor($decoded, $transaction->id);
        $this->assertNotNull($divergence, 'Expected the mismatched transaction to be reported as a divergence.');
        $this->assertEquals([['tax_type' => 'VAT', 'amount' => 120.00]], $divergence['reconstructed']);
        $this->assertSame('VAT', $divergence['actual'][0]['tax_type']);
        $this->assertEquals(999.00, (float) $divergence['actual'][0]['amount']);

        $descriptionText = implode(' | ', $divergence['descriptions']);
        $this->assertStringContainsString('VAT', $descriptionText);
        $this->assertStringContainsString('120.00', $descriptionText);
        $this->assertStringContainsString('999.00', $descriptionText);
    }

    /**
     * A payload row entirely missing from the actual persisted set (rather
     * than merely a differing amount on a shared tax_type) must also surface
     * as a divergence, described as missing.
     */
    public function test_reports_divergence_when_actual_rows_are_missing_a_payload_tax_type(): void
    {
        $transaction = $this->makeTransaction([
            'created_at' => '2031-02-01 09:00:00',
            'original_payload' => json_encode(['taxes' => [
                ['tax_type' => 'VAT', 'amount' => 50.00],
                ['tax_type' => 'OTHER_TAX', 'amount' => 10.00],
            ]]),
        ]);
        // Only VAT was actually persisted — OTHER_TAX is missing entirely.
        $this->linkTax($transaction, 'VAT', 50.00);

        $exitCode = Artisan::call('transactions:verify-tax-reconstruction', [
            '--from' => '2031-01-01',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertNotSame(0, $exitCode);
        $divergence = $this->divergenceFor($decoded, $transaction->id);
        $this->assertNotNull($divergence);
        $this->assertStringContainsString('OTHER_TAX', implode(' | ', $divergence['descriptions']));
    }

    public function test_reports_zero_divergences_when_reconstruction_matches_actual_rows_exactly(): void
    {
        $transaction = $this->makeTransaction([
            'created_at' => '2032-01-01 08:00:00',
            'original_payload' => json_encode(['taxes' => [
                ['tax_type' => 'VAT', 'amount' => 50.00],
                ['tax_type' => 'VATABLE_SALES', 'amount' => 500.00],
            ]]),
        ]);
        $this->linkTax($transaction, 'VAT', 50.00);
        $this->linkTax($transaction, 'VATABLE_SALES', 500.00);

        $exitCode = Artisan::call('transactions:verify-tax-reconstruction', [
            '--from' => '2032-01-01',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $decoded['divergence_count']);
        $this->assertNull($this->divergenceFor($decoded, $transaction->id));
        $this->assertSame(1, $decoded['checked_count']);
    }

    /**
     * Proves multiset (not naive per-index) comparison: two rows of the same
     * tax_type on both sides, in different orders, must match cleanly with
     * no false-positive divergence.
     */
    public function test_multiset_comparison_handles_duplicate_tax_types_without_false_positive(): void
    {
        $transaction = $this->makeTransaction([
            'created_at' => '2033-01-01 08:00:00',
            'original_payload' => json_encode(['taxes' => [
                ['tax_type' => 'OTHER_TAX', 'amount' => 5.00],
                ['tax_type' => 'OTHER_TAX', 'amount' => 15.00],
            ]]),
        ]);
        // Persisted in the opposite order — multiset equality must not care.
        $this->linkTax($transaction, 'OTHER_TAX', 15.00);
        $this->linkTax($transaction, 'OTHER_TAX', 5.00);

        $exitCode = Artisan::call('transactions:verify-tax-reconstruction', [
            '--from' => '2033-01-01',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertNull($this->divergenceFor($decoded, $transaction->id));
    }

    /**
     * A genuine duplicate-amount edge case: two OTHER_TAX rows worth 10.00
     * each on both sides must not be reported as a count mismatch, and a
     * genuinely unequal duplicate count must be.
     */
    public function test_multiset_comparison_detects_a_genuine_duplicate_count_mismatch(): void
    {
        $transaction = $this->makeTransaction([
            'created_at' => '2033-02-01 09:00:00',
            'original_payload' => json_encode(['taxes' => [
                ['tax_type' => 'OTHER_TAX', 'amount' => 10.00],
                ['tax_type' => 'OTHER_TAX', 'amount' => 10.00],
            ]]),
        ]);
        // Actual only has one such row — a genuine divergence.
        $this->linkTax($transaction, 'OTHER_TAX', 10.00);

        $exitCode = Artisan::call('transactions:verify-tax-reconstruction', [
            '--from' => '2033-01-01',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertNotSame(0, $exitCode);
        $this->assertNotNull($this->divergenceFor($decoded, $transaction->id));
    }

    /**
     * Code review finding: equal row count on both sides is not sufficient
     * for a match — the multisets themselves must be equal. Reconstructed
     * has two 20.00 VAT rows and one 10.00; actual has two 10.00 and one
     * 20.00. Same count (3), same total (50.00), but genuinely different
     * multisets. This is the shape a naive "compare sums" or "compare
     * counts" refactor of diff() would silently let through, so it must be
     * locked in as its own regression test.
     */
    public function test_diff_detects_equal_count_but_different_multiset(): void
    {
        $transaction = $this->makeTransaction([
            'created_at' => '2033-03-01 08:00:00',
            'original_payload' => json_encode(['taxes' => [
                ['tax_type' => 'VAT', 'amount' => 10.00],
                ['tax_type' => 'VAT', 'amount' => 20.00],
                ['tax_type' => 'VAT', 'amount' => 20.00],
            ]]),
        ]);
        $this->linkTax($transaction, 'VAT', 10.00);
        $this->linkTax($transaction, 'VAT', 10.00);
        $this->linkTax($transaction, 'VAT', 20.00);

        $exitCode = Artisan::call('transactions:verify-tax-reconstruction', [
            '--from' => '2033-03-01',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertNotSame(0, $exitCode);
        $divergence = $this->divergenceFor($decoded, $transaction->id);
        $this->assertNotNull($divergence, 'Equal-count, different-multiset rows must still be reported as a divergence.');
        $this->assertStringContainsString('amount mismatch on VAT', implode(' | ', $divergence['descriptions']));
    }

    public function test_transaction_without_linked_tax_rows_is_excluded_from_candidate_pool(): void
    {
        $withoutTaxes = $this->makeTransaction([
            'created_at' => '2034-01-01 08:00:00',
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 1.00]]]),
        ]);
        // No TransactionTax row linked — must be excluded, not reported.

        $withTaxes = $this->makeTransaction([
            'created_at' => '2034-01-01 09:00:00',
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 1.00]]]),
        ]);
        $this->linkTax($withTaxes, 'VAT', 1.00);

        $exitCode = Artisan::call('transactions:verify-tax-reconstruction', [
            '--from' => '2034-01-01',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        // Only the transaction with a linked row was checked.
        $this->assertSame(1, $decoded['checked_count']);
        $this->assertSame(1, $decoded['candidate_pool_size']);
        $this->assertNull($this->divergenceFor($decoded, $withoutTaxes->id));
        $this->assertNull($this->divergenceFor($decoded, $withTaxes->id));
    }

    /**
     * --sample=N caps the number of transactions checked. checked_count is
     * min(candidate_pool_size, sample) by construction (a SQL LIMIT), so as
     * long as this test's own fixtures alone already exceed the requested
     * sample size, checked_count === sample holds regardless of any other
     * eligible data that may exist elsewhere in this test run.
     */
    public function test_sample_option_caps_number_of_transactions_checked(): void
    {
        foreach (range(1, 4) as $i) {
            $transaction = $this->makeTransaction([
                'created_at' => "2035-01-01 0{$i}:00:00",
                'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 1.00]]]),
            ]);
            $this->linkTax($transaction, 'VAT', 1.00);
        }

        $exitCode = Artisan::call('transactions:verify-tax-reconstruction', [
            '--from' => '2035-01-01',
            '--sample' => 2,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, $decoded['checked_count']);
        $this->assertGreaterThanOrEqual(4, $decoded['candidate_pool_size']);
    }

    /**
     * An empty candidate population must not be reported as a vacuous
     * "0 divergences, success" — it's a failure of the safety gate itself.
     * Year 3000 guarantees zero candidates regardless of any other fixture
     * data in this test run.
     */
    public function test_empty_candidate_population_fails_with_clear_message(): void
    {
        $exitCode = Artisan::call('transactions:verify-tax-reconstruction', [
            '--from' => '3000-01-01',
        ]);
        $output = Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('No candidate transactions found', $output);

        $jsonExitCode = Artisan::call('transactions:verify-tax-reconstruction', [
            '--from' => '3000-01-01',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertNotSame(0, $jsonExitCode);
        $this->assertSame(0, $decoded['candidate_pool_size']);
        $this->assertSame(0, $decoded['checked_count']);
        // Not a false "0 divergences, success" — divergence_count being 0
        // here must not be confused with a clean pass; the non-zero exit
        // code and candidate_pool_size of 0 are what signal the real failure.
        $this->assertSame(0, $decoded['divergence_count']);
    }

    /**
     * --json output must structurally match the human output's underlying
     * data — one result object drives both (BackfillTransactionTaxes's
     * established pattern, reused here rather than duplicating formatting).
     */
    public function test_json_output_structurally_matches_human_output_data(): void
    {
        $matching = $this->makeTransaction([
            'created_at' => '2036-01-01 08:00:00',
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 20.00]]]),
        ]);
        $this->linkTax($matching, 'VAT', 20.00);

        $mismatched = $this->makeTransaction([
            'created_at' => '2036-01-01 09:00:00',
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 30.00]]]),
        ]);
        $this->linkTax($mismatched, 'VAT', 31.00);

        $humanExit = Artisan::call('transactions:verify-tax-reconstruction', ['--from' => '2036-01-01']);
        $humanOutput = Artisan::output();

        $jsonExit = Artisan::call('transactions:verify-tax-reconstruction', [
            '--from' => '2036-01-01',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertNotSame(0, $humanExit);
        $this->assertNotSame(0, $jsonExit);
        $this->assertSame(2, $decoded['checked_count']);
        $this->assertSame(1, $decoded['divergence_count']);

        // Human output surfaces the same underlying numbers and the
        // mismatched transaction id.
        $this->assertStringContainsString((string) $decoded['checked_count'], $humanOutput);
        $this->assertStringContainsString((string) $mismatched->id, $humanOutput);
        $this->assertStringContainsString('30.00', $humanOutput);
        $this->assertStringContainsString('31.00', $humanOutput);
    }

    public function test_invalid_from_option_is_rejected(): void
    {
        $exitCode = Artisan::call('transactions:verify-tax-reconstruction', [
            '--from' => 'not-a-date',
        ]);
        $output = Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Invalid --from', $output);
    }

    public function test_invalid_sample_option_is_rejected(): void
    {
        $exitCode = Artisan::call('transactions:verify-tax-reconstruction', [
            '--from' => '2037-01-01',
            '--sample' => 0,
        ]);
        $output = Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Invalid --sample', $output);
    }
}

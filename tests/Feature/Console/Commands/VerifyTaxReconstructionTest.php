<?php

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\VerifyTaxReconstruction;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionTax;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * 002-backfill-transaction-taxes, Slice 11 (T101).
     *
     * allocate() and sampleStratified() are protected — invoked here via
     * reflection with setAccessible(true), the same convention already used
     * elsewhere in this codebase for testing protected methods in isolation
     * (see tests/Feature/Services/Backfill/TaxBackfillRunnerTest.php).
     * sampleStratified() in particular is exercised directly (bypassing
     * Artisan::call) so the breadth tests below can inspect the actual
     * Transaction models selected — not just the coverage array's
     * self-reported numbers, per the slice-11 brief's own caution that "a
     * bug in the allocator could report numbers that don't match what was
     * actually queried."
     */
    private function allocate(int $total, array $capacities): array
    {
        $method = new \ReflectionMethod(VerifyTaxReconstruction::class, 'allocate');
        $method->setAccessible(true);

        return $method->invoke(new VerifyTaxReconstruction, $total, $capacities);
    }

    private function sampleStratified(Builder $candidateQuery, int $sampleSize): array
    {
        $method = new \ReflectionMethod(VerifyTaxReconstruction::class, 'sampleStratified');
        $method->setAccessible(true);

        return $method->invoke(new VerifyTaxReconstruction, $candidateQuery, $sampleSize);
    }

    public function test_allocate_gives_every_stratum_full_capacity_when_total_covers_all(): void
    {
        $result = $this->allocate(10, ['a' => 2, 'b' => 3, 'c' => 1]);

        $this->assertSame(['a' => 2, 'b' => 3, 'c' => 1], $result);
    }

    public function test_allocate_spreads_one_unit_per_bucket_before_any_bucket_gets_a_second(): void
    {
        $result = $this->allocate(2, ['a' => 5, 'b' => 5, 'c' => 5, 'd' => 5]);

        $this->assertSame(['a' => 1, 'b' => 1, 'c' => 0, 'd' => 0], $result);
    }

    public function test_allocate_round_robins_across_multiple_passes_once_a_bucket_is_full(): void
    {
        // a caps out at 1 on the first pass; b and c keep receiving on
        // later passes until the 4-unit budget is exhausted.
        $result = $this->allocate(4, ['a' => 1, 'b' => 3, 'c' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 1], $result);
    }

    public function test_allocate_handles_single_bucket_case(): void
    {
        $this->assertSame(['x' => 5], $this->allocate(5, ['x' => 10]));
        $this->assertSame(['x' => 10], $this->allocate(15, ['x' => 10]));
    }

    public function test_allocate_returns_all_zeros_for_zero_total(): void
    {
        $this->assertSame(['a' => 0, 'b' => 0], $this->allocate(0, ['a' => 5, 'b' => 5]));
    }

    public function test_allocate_handles_empty_capacities_without_error(): void
    {
        $this->assertSame([], $this->allocate(5, []));
    }

    /**
     * Breadth proof: 3 distinct days x 3 distinct tenants each (9 strata, 1
     * transaction per stratum). Requesting a sample that covers the full
     * pool must select every single fixture — verified by reading the
     * tenant_id/day of the actually-returned Transaction models, not just
     * coverage's self-reported counts.
     */
    public function test_stratified_sampling_spans_every_distinct_tenant_and_day_when_sample_covers_full_pool(): void
    {
        $days = ['2037-01-01', '2037-01-02', '2037-01-03'];
        $expectedTenantIds = [];

        foreach ($days as $day) {
            for ($i = 1; $i <= 3; $i++) {
                $transaction = $this->makeTransaction([
                    'created_at' => "{$day} 0{$i}:00:00",
                    'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 10.00]]]),
                ]);
                $this->linkTax($transaction, 'VAT', 10.00);

                $expectedTenantIds[] = $transaction->tenant_id;
            }
        }

        $candidateQuery = Transaction::query()
            ->where('created_at', '>=', '2037-01-01')
            ->where('created_at', '<', '2037-01-04')
            ->whereHas('taxes');

        [$transactions, $coverage] = $this->sampleStratified($candidateQuery, 9);

        $this->assertCount(9, $transactions);

        $actualTenantIds = $transactions->pluck('tenant_id')->values()->all();
        $actualDays = $transactions->pluck('created_at')
            ->map(fn ($dt) => $dt->toDateString())
            ->unique()
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing($expectedTenantIds, $actualTenantIds);
        $this->assertEqualsCanonicalizing($days, $actualDays);

        $this->assertSame(9, $coverage['total_strata']);
        $this->assertSame(9, $coverage['sampled_strata']);
    }

    /**
     * Breadth-under-budget proof: 4 distinct days x 3 distinct tenants each
     * (12 strata), sample budget of 5 — smaller than total_strata. The
     * round-robin allocator must spread across as many distinct strata as
     * the budget allows (min(sample, total_strata) = 5), spanning all 4
     * days rather than clustering into fewer days than the budget permits.
     * Verified against the actual selected Transaction models' day/tenant,
     * cross-checked against coverage's self-reported numbers.
     */
    public function test_stratified_sampling_spreads_breadth_first_when_sample_is_smaller_than_total_strata(): void
    {
        $days = ['2037-02-01', '2037-02-02', '2037-02-03', '2037-02-04'];

        foreach ($days as $day) {
            for ($i = 1; $i <= 3; $i++) {
                $transaction = $this->makeTransaction([
                    'created_at' => "{$day} 0{$i}:00:00",
                    'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 10.00]]]),
                ]);
                $this->linkTax($transaction, 'VAT', 10.00);
            }
        }

        $candidateQuery = Transaction::query()
            ->where('created_at', '>=', '2037-02-01')
            ->where('created_at', '<', '2037-02-05')
            ->whereHas('taxes');

        [$transactions, $coverage] = $this->sampleStratified($candidateQuery, 5);

        $this->assertCount(5, $transactions);
        $this->assertSame(12, $coverage['total_strata']);
        $this->assertSame(5, $coverage['sampled_strata']);

        // Every distinct day must be represented — 4 days <= budget of 5,
        // so round-robin water-filling must not cluster into fewer days.
        $sampledDays = $transactions->pluck('created_at')
            ->map(fn ($dt) => $dt->toDateString())
            ->unique()
            ->values()
            ->all();
        $this->assertCount(4, $sampledDays, 'Expected every distinct day to receive at least one sampled transaction.');

        // Cross-check: the actual distinct (day, tenant) pairs among the
        // returned models must independently match coverage's self-reported
        // sampled_strata — this is the "not just from coverage's
        // self-reported numbers" proof from the slice-11 brief.
        $actualStrataCount = $transactions
            ->map(fn ($t) => $t->created_at->toDateString().'|'.$t->tenant_id)
            ->unique()
            ->count();
        $this->assertSame(5, $actualStrataCount);
        $this->assertSame($actualStrataCount, $coverage['sampled_strata']);
    }

    /**
     * coverage's per_day/per_tenant pool counts and total_strata must match
     * independently-computed fixture totals, via the full command
     * (Artisan::call + --json), not the reflection shortcut used above.
     */
    public function test_coverage_output_matches_independently_computed_fixture_totals(): void
    {
        $days = ['2037-03-01', '2037-03-02'];
        $tenantIdsByDay = [];

        foreach ($days as $day) {
            for ($i = 1; $i <= 2; $i++) {
                $transaction = $this->makeTransaction([
                    'created_at' => "{$day} 0{$i}:00:00",
                    'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 5.00]]]),
                ]);
                $this->linkTax($transaction, 'VAT', 5.00);

                $tenantIdsByDay[$day][] = $transaction->tenant_id;
            }
        }

        $exitCode = Artisan::call('transactions:verify-tax-reconstruction', [
            '--from' => '2037-03-01',
            '--sample' => 4,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(4, $decoded['checked_count']);

        $coverage = $decoded['coverage'];
        $this->assertSame(4, $coverage['total_strata']);
        $this->assertSame(4, $coverage['sampled_strata']);

        $this->assertCount(2, $coverage['per_day']);
        foreach ($coverage['per_day'] as $row) {
            $this->assertContains($row['day'], $days);
            $this->assertSame(2, $row['pool_count']);
            $this->assertSame(2, $row['sampled_count']);
        }

        $allTenantIds = array_merge(...array_values($tenantIdsByDay));
        $this->assertCount(4, $coverage['per_tenant']);
        foreach ($coverage['per_tenant'] as $row) {
            $this->assertContains($row['tenant_id'], $allTenantIds);
            $this->assertSame(1, $row['pool_count']);
            $this->assertSame(1, $row['sampled_count']);
        }
    }

    /**
     * Regression (post-implementation review): the per-stratum row draw in
     * sampleStratified() previously built a *fresh* Transaction::query()
     * scoped only by tenant_id + whereDate('created_at', $day), instead of
     * extending the already-correctly-scoped $candidateQuery. That silently
     * dropped the --from lower bound on the boundary day whenever --from
     * carries a time component: whereDate() alone re-admits rows from
     * earlier the same calendar day, before the cutoff time. This is not a
     * theoretical edge case — research.md documents 2026-08-10 as a real
     * straddling day (2,694 with taxes / 2,032 without, straddling the
     * ~10:00 fix), which is exactly the scenario DEFAULT_FROM's own
     * docblock is built around ("an operator ... can override with
     * --from=2026-08-10 or a more precise timestamp").
     *
     * Fixture: one pre-cutoff and one post-cutoff transaction on the same
     * boundary day. Both candidate_pool_size/coverage (full command path)
     * and the actually-drawn transaction ids (direct sampleStratified()
     * call) must exclude the pre-cutoff row.
     */
    public function test_stratified_sampling_respects_from_time_component_on_the_boundary_day(): void
    {
        $preCutoff = $this->makeTransaction([
            'created_at' => '2037-04-01 08:00:00',
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 1.00]]]),
        ]);
        $this->linkTax($preCutoff, 'VAT', 1.00);

        $postCutoff = $this->makeTransaction([
            'created_at' => '2037-04-01 12:00:00',
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 2.00]]]),
        ]);
        $this->linkTax($postCutoff, 'VAT', 2.00);

        $exitCode = Artisan::call('transactions:verify-tax-reconstruction', [
            '--from' => '2037-04-01 10:00:00',
            '--sample' => 10,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        // The pre-cutoff transaction must not even be in the candidate pool.
        $this->assertSame(1, $decoded['candidate_pool_size']);
        $this->assertSame(1, $decoded['checked_count']);

        $coverage = $decoded['coverage'];
        $this->assertSame(1, $coverage['total_strata']);
        $this->assertSame(1, $coverage['sampled_strata']);
        $this->assertCount(1, $coverage['per_day']);
        $this->assertSame('2037-04-01', $coverage['per_day'][0]['day']);
        $this->assertSame(1, $coverage['per_day'][0]['pool_count'], 'Boundary-day pool_count must exclude the pre-cutoff row.');
        $this->assertSame(1, $coverage['per_day'][0]['sampled_count']);

        // Direct proof against the actual selected models, independent of
        // candidate_pool_size/coverage's self-reported numbers.
        $candidateQuery = Transaction::query()
            ->where('created_at', '>=', '2037-04-01 10:00:00')
            ->whereHas('taxes');

        [$transactions] = $this->sampleStratified($candidateQuery, 10);

        $sampledIds = $transactions->pluck('id')->all();
        $this->assertNotContains($preCutoff->id, $sampledIds, 'Pre-cutoff transaction must never appear in the drawn sample.');
        $this->assertContains($postCutoff->id, $sampledIds);
    }
}

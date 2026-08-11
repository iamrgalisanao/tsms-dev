<?php

namespace Tests\Feature\Console\Commands;

use App\Models\PosTerminal;
use App\Models\TaxBackfillRun;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionTax;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes, Slice 4 (T017-T022).
 *
 * Covers the `transactions:backfill-taxes` command: input validation, the
 * dry-run-only guarantee (including outright --apply rejection), repeatable
 * --tenant, and that human/--json output are built from the same result.
 *
 * Known test-isolation issue (tests/TestCase.php:38 breaks RefreshDatabase):
 * every count-sensitive assertion below is scoped to this test's own
 * fixtures — by tenant id, by this invocation's run id, or via an id-
 * watermark delta captured immediately before the command runs — never
 * against table-wide/global counts.
 */
class BackfillTransactionTaxesTest extends TestCase
{
    private function makeTransaction(Tenant $tenant, array $attributes = []): Transaction
    {
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        return Transaction::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
        ], $attributes));
    }

    /**
     * Watermark of the highest existing TaxBackfillRun id, so a later
     * assertion can check "no run created by this invocation" via a delta
     * (`id > $watermark`) instead of a global/table-wide count.
     */
    private function taxBackfillRunWatermark(): int
    {
        return (int) (TaxBackfillRun::max('id') ?? 0);
    }

    /**
     * Extracts the 5-numeric-column totals row (Scanned, Reconstructed,
     * Skipped, Quarantined, Failed) from the human-readable table, ignoring
     * the 6-column per-tenant/per-day tables and the non-numeric per-day
     * date column. Anchored to a full line (^...$) so a 6-column row can
     * never partially match as if it were a 5-column one.
     *
     * @return array<int, int>|null
     */
    private function extractTotalsRowFromHumanOutput(string $output): ?array
    {
        if (! preg_match('/^\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|$/m', $output, $matches)) {
            return null;
        }

        return array_map('intval', array_slice($matches, 1));
    }

    public function test_from_to_window_produces_matching_counts_in_human_and_json_output(): void
    {
        $tenant = Tenant::factory()->create();
        $day = '2026-06-13';

        $skipped = $this->makeTransaction($tenant, ['created_at' => "{$day} 08:00:00"]);
        TransactionTax::create([
            'transaction_pk' => $skipped->id,
            'tax_type' => 'VAT',
            'amount' => 10.00,
        ]);

        $applied = $this->makeTransaction($tenant, [
            'created_at' => "{$day} 09:00:00",
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 50.00]]]),
            'vat_amount' => 50.00,
        ]);

        $quarantined = $this->makeTransaction($tenant, [
            'created_at' => "{$day} 10:00:00",
            'original_payload' => null,
        ]);

        $params = [
            '--from' => $day,
            '--to' => '2026-06-14',
            '--tenant' => [$tenant->id],
        ];

        $humanExit = Artisan::call('transactions:backfill-taxes', $params);
        $humanOutput = Artisan::output();

        $jsonExit = Artisan::call('transactions:backfill-taxes', $params + ['--json' => true]);
        $jsonOutput = Artisan::output();

        $this->assertSame(0, $humanExit);
        $this->assertSame(0, $jsonExit);

        $decoded = json_decode($jsonOutput, true);
        $this->assertIsArray($decoded);
        $this->assertSame(3, $decoded['totals']['scanned']);
        $this->assertSame(1, $decoded['totals']['reconstructed']);
        $this->assertSame(1, $decoded['totals']['skipped_existing']);
        $this->assertSame(1, $decoded['totals']['quarantined']);
        $this->assertSame(0, $decoded['totals']['failed']);

        // Human and JSON output must agree on the same underlying numbers —
        // both are built from one shared result object.
        $humanTotals = $this->extractTotalsRowFromHumanOutput($humanOutput);
        $this->assertNotNull($humanTotals, 'Expected a 5-column totals row in human output: '.$humanOutput);
        $this->assertSame(
            [
                $decoded['totals']['scanned'],
                $decoded['totals']['reconstructed'],
                $decoded['totals']['skipped_existing'],
                $decoded['totals']['quarantined'],
                $decoded['totals']['failed'],
            ],
            $humanTotals
        );

        // Zero transaction_taxes writes, scoped to this test's own fixture
        // transaction ids (not a table-wide count).
        $this->assertSame(0, DB::table('transaction_taxes')
            ->whereIn('transaction_pk', [$applied->id, $quarantined->id])
            ->count());
    }

    public function test_day_option_works_as_alternative_to_from_and_to(): void
    {
        $tenant = Tenant::factory()->create();
        $day = '2026-07-01';

        $this->makeTransaction($tenant, [
            'created_at' => "{$day} 12:00:00",
            'original_payload' => null,
        ]);

        $exitCode = Artisan::call('transactions:backfill-taxes', [
            '--day' => $day,
            '--tenant' => [$tenant->id],
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $decoded['totals']['scanned']);
        $this->assertSame(1, $decoded['totals']['quarantined']);

        $run = TaxBackfillRun::findOrFail($decoded['run_id']);
        $this->assertSame($day.' 00:00:00', $run->window_start->format('Y-m-d H:i:s'));
        $this->assertSame($day.' 23:59:59', $run->window_end->format('Y-m-d H:i:s'));
    }

    public function test_both_day_and_from_or_neither_is_rejected_before_any_run_is_created(): void
    {
        $watermark = $this->taxBackfillRunWatermark();

        $bothExit = Artisan::call('transactions:backfill-taxes', [
            '--day' => '2026-06-13',
            '--from' => '2026-06-13',
        ]);
        $bothOutput = Artisan::output();

        $this->assertNotSame(0, $bothExit);
        $this->assertStringContainsString('--day', $bothOutput);

        $neitherExit = Artisan::call('transactions:backfill-taxes', []);
        $neitherOutput = Artisan::output();

        $this->assertNotSame(0, $neitherExit);
        $this->assertStringContainsString('--day', $neitherOutput);

        // Validation happens before the runner runs at all — no TaxBackfillRun
        // row created by either invocation (delta from the pre-test watermark,
        // not a global/table-wide count).
        $this->assertSame(0, TaxBackfillRun::where('id', '>', $watermark)->count());
    }

    /**
     * Code review finding (Slice 4): --to is exclusive (resolved to one
     * second before its own midnight), so --from == --to always resolves to
     * an inverted/empty window via whereBetween() — almost certainly an
     * operator typo, not a legitimate request. This must be rejected before
     * the runner ever executes, not reported as a clean empty `completed`
     * run.
     */
    public function test_equal_from_and_to_is_rejected_as_an_empty_window_before_any_run_is_created(): void
    {
        $watermark = $this->taxBackfillRunWatermark();

        $exitCode = Artisan::call('transactions:backfill-taxes', [
            '--from' => '2026-06-13',
            '--to' => '2026-06-13',
        ]);
        $output = Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('empty window', $output);
        $this->assertSame(0, TaxBackfillRun::where('id', '>', $watermark)->count());
    }

    /**
     * Code review finding (Slice 4): tenant id 0 is not a real tenant in
     * this system, and --chunk=0 / --limit=0 are already rejected by
     * isPositiveInteger(). --tenant=0 must be rejected the same way, with no
     * special-case carve-out.
     */
    public function test_tenant_zero_is_rejected_same_as_chunk_and_limit_zero(): void
    {
        $watermark = $this->taxBackfillRunWatermark();

        $exitCode = Artisan::call('transactions:backfill-taxes', [
            '--day' => '2026-06-13',
            '--tenant' => ['0'],
        ]);
        $output = Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('--tenant', $output);
        $this->assertSame(0, TaxBackfillRun::where('id', '>', $watermark)->count());
    }

    public function test_multiple_tenant_flags_restrict_output_to_just_those_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $tenantC = Tenant::factory()->create();
        $day = '2026-06-20';

        $this->makeTransaction($tenantA, ['created_at' => "{$day} 08:00:00", 'original_payload' => null]);
        $this->makeTransaction($tenantB, ['created_at' => "{$day} 09:00:00", 'original_payload' => null]);
        $this->makeTransaction($tenantC, ['created_at' => "{$day} 10:00:00", 'original_payload' => null]);

        $exitCode = Artisan::call('transactions:backfill-taxes', [
            '--day' => $day,
            '--tenant' => [$tenantA->id, $tenantB->id],
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, $decoded['totals']['scanned']);

        $tenantIdsInBreakdown = collect($decoded['per_tenant'])->pluck('tenant_id')->sort()->values()->all();
        $this->assertSame(
            collect([$tenantA->id, $tenantB->id])->sort()->values()->all(),
            $tenantIdsInBreakdown
        );
        $this->assertFalse(in_array($tenantC->id, $tenantIdsInBreakdown, true));
    }

    public function test_apply_is_rejected_outright_with_zero_writes_and_no_run_created(): void
    {
        $tenant = Tenant::factory()->create();
        $day = '2026-06-25';

        $tx = $this->makeTransaction($tenant, [
            'created_at' => "{$day} 08:00:00",
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 25.00]]]),
            'vat_amount' => 25.00,
        ]);

        $watermark = $this->taxBackfillRunWatermark();

        $exitCode = Artisan::call('transactions:backfill-taxes', [
            '--day' => $day,
            '--tenant' => [$tenant->id],
            '--apply' => true,
        ]);
        $output = Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('--apply', $output);
        $this->assertStringContainsString('not yet implemented', $output);

        // No TaxBackfillRun created for this invocation (delta from watermark).
        $this->assertSame(0, TaxBackfillRun::where('id', '>', $watermark)->count());

        // Zero transaction_taxes writes for this fixture's transaction.
        $this->assertSame(0, DB::table('transaction_taxes')->where('transaction_pk', $tx->id)->count());
    }

    public function test_per_tenant_and_per_day_breakdowns_sum_correctly_to_run_level_totals(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        // Two days, two tenants, mixed outcomes.
        $this->makeTransaction($tenantA, ['created_at' => '2026-06-13 08:00:00', 'original_payload' => null]);
        $skippedA = $this->makeTransaction($tenantA, ['created_at' => '2026-06-14 08:00:00']);
        TransactionTax::create(['transaction_pk' => $skippedA->id, 'tax_type' => 'VAT', 'amount' => 5.00]);
        $this->makeTransaction($tenantB, [
            'created_at' => '2026-06-13 09:00:00',
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 15.00]]]),
            'vat_amount' => 15.00,
        ]);
        $this->makeTransaction($tenantB, ['created_at' => '2026-06-14 09:00:00', 'original_payload' => null]);

        $exitCode = Artisan::call('transactions:backfill-taxes', [
            '--from' => '2026-06-13',
            '--to' => '2026-06-15',
            '--tenant' => [$tenantA->id, $tenantB->id],
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(4, $decoded['totals']['scanned']);

        foreach (['scanned', 'reconstructed', 'skipped_existing', 'quarantined', 'failed'] as $field) {
            $tenantSum = collect($decoded['per_tenant'])->sum($field);
            $daySum = collect($decoded['per_day'])->sum($field);

            $this->assertSame(
                $decoded['totals'][$field],
                $tenantSum,
                "per_tenant sums do not match run totals for '{$field}'"
            );
            $this->assertSame(
                $decoded['totals'][$field],
                $daySum,
                "per_day sums do not match run totals for '{$field}'"
            );
        }
    }

    public function test_zero_writes_to_transaction_taxes_regardless_of_outcome_mix(): void
    {
        $tenant = Tenant::factory()->create();
        $day = '2026-06-30';

        $applied = $this->makeTransaction($tenant, [
            'created_at' => "{$day} 08:00:00",
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 30.00]]]),
            'vat_amount' => 30.00,
        ]);
        $quarantined = $this->makeTransaction($tenant, [
            'created_at' => "{$day} 09:00:00",
            'original_payload' => null,
        ]);

        Artisan::call('transactions:backfill-taxes', [
            '--day' => $day,
            '--tenant' => [$tenant->id],
        ]);

        $this->assertSame(0, DB::table('transaction_taxes')
            ->whereIn('transaction_pk', [$applied->id, $quarantined->id])
            ->count());
    }
}

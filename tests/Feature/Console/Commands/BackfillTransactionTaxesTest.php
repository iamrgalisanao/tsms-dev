<?php

namespace Tests\Feature\Console\Commands;

use App\Models\PosTerminal;
use App\Models\TaxBackfillRun;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionTax;
use App\Services\Backfill\TaxBackfillRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    /**
     * Slice 8 (T017-... apply wiring): --apply given alongside --from/--to
     * (instead of --day) must be rejected before the runner ever runs —
     * whole-window apply is structurally forbidden.
     */
    public function test_apply_with_from_and_to_instead_of_day_is_rejected_with_zero_writes_and_no_run_created(): void
    {
        $tenant = Tenant::factory()->create();

        $tx = $this->makeTransaction($tenant, [
            'created_at' => '2026-06-25 08:00:00',
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 25.00]]]),
            'vat_amount' => 25.00,
        ]);

        $watermark = $this->taxBackfillRunWatermark();

        $exitCode = Artisan::call('transactions:backfill-taxes', [
            '--from' => '2026-06-25',
            '--to' => '2026-06-26',
            '--tenant' => [$tenant->id],
            '--apply' => true,
        ]);
        $output = Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('--apply', $output);
        $this->assertStringContainsString('--day', $output);

        $this->assertSame(0, TaxBackfillRun::where('id', '>', $watermark)->count());
        $this->assertSame(0, DB::table('transaction_taxes')->where('transaction_pk', $tx->id)->count());
    }

    /**
     * Slice 8: --apply given with --day entirely missing must also be
     * rejected before the runner ever runs.
     */
    public function test_apply_without_day_is_rejected_with_zero_writes_and_no_run_created(): void
    {
        $watermark = $this->taxBackfillRunWatermark();

        $exitCode = Artisan::call('transactions:backfill-taxes', [
            '--apply' => true,
        ]);
        $output = Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('--apply', $output);
        $this->assertStringContainsString('--day', $output);

        $this->assertSame(0, TaxBackfillRun::where('id', '>', $watermark)->count());
    }

    /**
     * Slice 8: --apply --day=<valid> against a clean fixture actually writes
     * real transaction_taxes rows, with mode=apply, status=completed, and
     * exit code 0. --throttle is set low here (this test isn't exercising
     * the default) to keep the test fast.
     */
    public function test_apply_with_day_and_clean_fixture_writes_real_tax_rows_and_completes(): void
    {
        $tenant = Tenant::factory()->create();
        $day = '2026-07-10';

        $applied = $this->makeTransaction($tenant, [
            'created_at' => "{$day} 08:00:00",
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 40.00]]]),
            'vat_amount' => 40.00,
        ]);

        $exitCode = Artisan::call('transactions:backfill-taxes', [
            '--day' => $day,
            '--tenant' => [$tenant->id],
            '--apply' => true,
            '--throttle' => 1,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('apply', $decoded['mode']);
        $this->assertSame(TaxBackfillRun::STATUS_COMPLETED, $decoded['status']);
        $this->assertSame(1, $decoded['totals']['scanned']);
        $this->assertSame(1, $decoded['totals']['reconstructed']);
        $this->assertSame(0, $decoded['totals']['failed']);

        $this->assertSame(1, DB::table('transaction_taxes')
            ->where('transaction_pk', $applied->id)
            ->where('tax_type', 'VAT')
            ->where('amount', 40.00)
            ->count());
    }

    /**
     * Slice 8 (code review fix): when --apply is given without --throttle,
     * the command must invoke TaxBackfillRunner::apply() with the
     * conservative default of 500. Proved deterministically via a mock bound
     * into the container instead of wall-clock timing (a flakiness risk
     * under CI load) — the mock still returns a real, persisted
     * TaxBackfillRun so buildResult()/render() run against genuine data
     * exactly as they would with the real runner.
     */
    public function test_apply_without_throttle_option_uses_conservative_default_of_500ms(): void
    {
        $tenant = Tenant::factory()->create();
        $day = '2026-07-11';

        $run = TaxBackfillRun::factory()->apply()->completed()->create([
            'window_start' => "{$day} 00:00:00",
            'window_end' => "{$day} 23:59:59",
        ]);

        $this->mock(TaxBackfillRunner::class, function ($mock) use ($tenant, $run) {
            $mock->shouldReceive('apply')
                ->once()
                ->withArgs(function ($day, $tenantIds, $limit, $chunk, $throttleMs, $killSwitchPath) use ($tenant) {
                    return $day instanceof Carbon
                        && $day->format('Y-m-d') === '2026-07-11'
                        && $tenantIds === [$tenant->id]
                        && $limit === null
                        && $chunk === 500
                        && $throttleMs === 500
                        && $killSwitchPath === null;
                })
                ->andReturn($run);
        });

        $exitCode = Artisan::call('transactions:backfill-taxes', [
            '--day' => $day,
            '--tenant' => [$tenant->id],
            '--apply' => true,
        ]);

        $this->assertSame(0, $exitCode);
    }

    /**
     * Slice 8 (code review fix): an explicit --throttle overrides the 500
     * default — proved the same mock-based way, for consistency with the
     * default-throttle test above.
     */
    public function test_apply_with_explicit_throttle_option_overrides_the_default(): void
    {
        $tenant = Tenant::factory()->create();
        $day = '2026-07-12';

        $run = TaxBackfillRun::factory()->apply()->completed()->create([
            'window_start' => "{$day} 00:00:00",
            'window_end' => "{$day} 23:59:59",
        ]);

        $this->mock(TaxBackfillRunner::class, function ($mock) use ($tenant, $run) {
            $mock->shouldReceive('apply')
                ->once()
                ->withArgs(function ($day, $tenantIds, $limit, $chunk, $throttleMs, $killSwitchPath) use ($tenant) {
                    return $day instanceof Carbon
                        && $day->format('Y-m-d') === '2026-07-12'
                        && $tenantIds === [$tenant->id]
                        && $limit === null
                        && $chunk === 500
                        && $throttleMs === 5
                        && $killSwitchPath === null;
                })
                ->andReturn($run);
        });

        $exitCode = Artisan::call('transactions:backfill-taxes', [
            '--day' => $day,
            '--tenant' => [$tenant->id],
            '--apply' => true,
            '--throttle' => 5,
        ]);

        $this->assertSame(0, $exitCode);
    }

    /**
     * Slice 8 (code review fix): --throttle must be validated the same way
     * --chunk/--limit already are — a malformed value silently (int)-casts
     * ("5oo" -> 5, "abc" -> 0) with no operator feedback, which could turn
     * the conservative 500 default's safety margin into a near-zero throttle
     * by typo. Zero is rejected too, same as --chunk=0/--limit=0.
     */
    public function test_invalid_throttle_value_is_rejected_before_any_run_is_created(): void
    {
        $tenant = Tenant::factory()->create();
        $watermark = $this->taxBackfillRunWatermark();

        $exitCode = Artisan::call('transactions:backfill-taxes', [
            '--day' => '2026-07-11',
            '--tenant' => [$tenant->id],
            '--apply' => true,
            '--throttle' => 'abc',
        ]);
        $output = Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('--throttle', $output);
        $this->assertSame(0, TaxBackfillRun::where('id', '>', $watermark)->count());

        $zeroExitCode = Artisan::call('transactions:backfill-taxes', [
            '--day' => '2026-07-11',
            '--tenant' => [$tenant->id],
            '--apply' => true,
            '--throttle' => 0,
        ]);
        $zeroOutput = Artisan::output();

        $this->assertNotSame(0, $zeroExitCode);
        $this->assertStringContainsString('--throttle', $zeroOutput);
        $this->assertSame(0, TaxBackfillRun::where('id', '>', $watermark)->count());
    }

    /**
     * Slice 8: a kill-switch sentinel present before the run starts stops
     * the run cleanly before its first chunk is processed — a distinct exit
     * code from a generic failure, and output text that says "stopped," not
     * "failed."
     */
    public function test_apply_with_kill_switch_path_present_stops_cleanly_with_distinct_exit_code(): void
    {
        $tenant = Tenant::factory()->create();
        $day = '2026-07-13';

        $tx = $this->makeTransaction($tenant, [
            'created_at' => "{$day} 08:00:00",
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 15.00]]]),
            'vat_amount' => 15.00,
        ]);

        $killSwitchPath = sys_get_temp_dir().'/tsms_tax_backfill_kill_switch_'.uniqid('', true);
        file_put_contents($killSwitchPath, '');

        try {
            $exitCode = Artisan::call('transactions:backfill-taxes', [
                '--day' => $day,
                '--tenant' => [$tenant->id],
                '--apply' => true,
                '--throttle' => 1,
                '--kill-switch-path' => $killSwitchPath,
                '--json' => true,
            ]);
            $output = Artisan::output();
            $decoded = json_decode($output, true);

            $this->assertNotSame(0, $exitCode);
            $this->assertNotSame(1, $exitCode, 'Stopped runs must use a distinct exit code from a generic failure.');
            $this->assertIsArray($decoded, 'Expected valid JSON output: '.$output);
            $this->assertSame(TaxBackfillRun::STATUS_STOPPED, $decoded['status']);
            $this->assertStringContainsString('"status": "stopped"', $output);
            $this->assertStringNotContainsString('"status": "failed"', $output);
            $this->assertSame(0, $decoded['totals']['scanned']);

            $this->assertSame(0, DB::table('transaction_taxes')->where('transaction_pk', $tx->id)->count());
        } finally {
            @unlink($killSwitchPath);
        }
    }

    /**
     * Slice 8: the human-output header must reflect the actual run mode —
     * "apply run" for --apply, "dry run" (unchanged) otherwise.
     */
    public function test_apply_and_dry_run_headers_are_mode_aware(): void
    {
        $tenant = Tenant::factory()->create();
        $day = '2026-07-14';

        $this->makeTransaction($tenant, [
            'created_at' => "{$day} 08:00:00",
            'original_payload' => null,
        ]);

        Artisan::call('transactions:backfill-taxes', [
            '--day' => $day,
            '--tenant' => [$tenant->id],
        ]);
        $dryRunOutput = Artisan::output();

        $this->assertStringContainsString('dry run', $dryRunOutput);
        $this->assertStringNotContainsString('apply run', $dryRunOutput);

        Artisan::call('transactions:backfill-taxes', [
            '--day' => $day,
            '--tenant' => [$tenant->id],
            '--apply' => true,
            '--throttle' => 1,
        ]);
        $applyRunOutput = Artisan::output();

        $this->assertStringContainsString('apply run', $applyRunOutput);
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

    /**
     * Slice 9 (T097): a schema pre-flight failure surfaces through the CLI
     * with a distinct exit code (self::EXIT_PREFLIGHT_FAILED = 4, never
     * conflated with a generic failure) and output that names which check
     * failed, in both --json and human form.
     *
     * MySQL refuses to drop an index a foreign key still depends on, so a
     * throwaway holder index on the same column is added first purely to
     * satisfy that requirement while idx_tx_taxes_pk itself is dropped — it
     * plays no other role here. ALTER TABLE causes an implicit commit in
     * MySQL/InnoDB, flushing this test's ambient RefreshDatabase transaction
     * (the known isolation gap tracked at tests/TestCase.php:38), so
     * restoration is explicit and verified via a real Schema::hasIndex()
     * assertion inside `finally` itself — not a normal assertion an early
     * failure could skip.
     */
    public function test_apply_against_a_forced_preflight_failure_exits_with_the_distinct_code_and_names_the_failed_check(): void
    {
        $tenant = Tenant::factory()->create();
        $day = '2026-07-20';

        $tx = $this->makeTransaction($tenant, [
            'created_at' => "{$day} 08:00:00",
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 45.00]]]),
            'vat_amount' => 45.00,
        ]);

        DB::statement('ALTER TABLE transaction_taxes ADD INDEX idx_tmp_cli_preflight_holder (transaction_pk)');
        DB::statement('ALTER TABLE transaction_taxes DROP INDEX idx_tx_taxes_pk');

        try {
            $jsonExit = Artisan::call('transactions:backfill-taxes', [
                '--day' => $day,
                '--tenant' => [$tenant->id],
                '--apply' => true,
                '--json' => true,
            ]);
            $jsonOutput = Artisan::output();
            $decoded = json_decode($jsonOutput, true);

            $this->assertSame(4, $jsonExit);
            $this->assertIsArray($decoded, 'Expected valid JSON output: '.$jsonOutput);
            $this->assertSame(TaxBackfillRun::STATUS_PREFLIGHT_FAILED, $decoded['status']);
            $this->assertSame(0, $decoded['totals']['scanned']);
            $this->assertIsArray($decoded['preflight_checks']);
            $this->assertFalse($decoded['preflight_checks']['index_present']);
            $this->assertTrue($decoded['preflight_checks']['fk_present']);

            $humanExit = Artisan::call('transactions:backfill-taxes', [
                '--day' => $day,
                '--tenant' => [$tenant->id],
                '--apply' => true,
            ]);
            $humanOutput = Artisan::output();

            $this->assertSame(4, $humanExit);
            $this->assertStringContainsString('PRE-FLIGHT FAILED', $humanOutput);
            $this->assertStringContainsString('idx_tx_taxes_pk', $humanOutput);

            $this->assertSame(0, DB::table('transaction_taxes')->where('transaction_pk', $tx->id)->count());
        } finally {
            DB::statement('ALTER TABLE transaction_taxes ADD INDEX idx_tx_taxes_pk (transaction_pk)');
            DB::statement('ALTER TABLE transaction_taxes DROP INDEX idx_tmp_cli_preflight_holder');

            $this->assertTrue(
                Schema::hasIndex('transaction_taxes', 'idx_tx_taxes_pk'),
                'CRITICAL: idx_tx_taxes_pk was not restored after this test — the shared test database schema is now broken for every subsequent test run.'
            );
        }
    }

    /**
     * Slice 9 (T097): dry-run output never includes a real preflight_checks
     * result — dryRun() never calls the checker.
     */
    public function test_dry_run_json_output_has_null_preflight_checks(): void
    {
        $tenant = Tenant::factory()->create();
        $day = '2026-07-21';

        $this->makeTransaction($tenant, [
            'created_at' => "{$day} 08:00:00",
            'original_payload' => null,
        ]);

        $exitCode = Artisan::call('transactions:backfill-taxes', [
            '--day' => $day,
            '--tenant' => [$tenant->id],
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertArrayHasKey('preflight_checks', $decoded);
        $this->assertNull($decoded['preflight_checks']);
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

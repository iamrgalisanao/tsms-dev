<?php

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\TaxBackfillMaterialityReport;
use App\Models\PosTerminal;
use App\Models\PreBackfillSnapshotRecord;
use App\Models\PreBackfillSnapshotRun;
use App\Models\TaxBackfillMaterialityRecord;
use App\Models\TaxBackfillMaterialityRun;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Reports\SalesReportDataService;
use App\Services\Reports\SalesReportFilter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes, Slice 19 (T047/Command 3). See
 * specs/002-backfill-transaction-taxes/slice-19-materiality-brief.md.
 *
 * Covers `transactions:tax-backfill-materiality`: pure delta/threshold
 * logic, refusal when no completed snapshot run exists, per-pair
 * source-mismatch isolation (FR-012a, via a genuine daily_summary vs.
 * raw_transactions flip, not a mock), idempotency (refuse/--force),
 * read-time threshold re-evaluation without a new report-path call or
 * write, and verbatim after_rendered_result persistence.
 *
 * Named with "Backfill" in the class so it is discoverable via this
 * feature's `--filter=Backfill` convention. Known test-isolation issue
 * (tests/TestCase.php:38 breaks RefreshDatabase) worked around the same way
 * SnapshotPreBackfillAggregatesTest does: every test uses its own
 * never-reused calendar month (2031) so leaked rows from one test can never
 * collide with another's idempotency-key assumptions.
 */
class BackfillTaxMaterialityReportTest extends TestCase
{
    private const SNAPSHOT_COMMAND = 'transactions:snapshot-pre-backfill-aggregates';

    private const COMMAND = 'transactions:tax-backfill-materiality';

    private function makeTransaction(Tenant $tenant, string $atDate): Transaction
    {
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $timestamp = Carbon::parse($atDate);

        return Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_timestamp' => $timestamp,
            'created_at' => $timestamp,
        ]);
    }

    private function markDailySummaryComplete(Tenant $tenant, PosTerminal $terminal, string $month, float $otherTax): void
    {
        $start = Carbon::parse($month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            DB::table('report_refresh_states')->updateOrInsert([
                'report_type' => 'daily_transaction_summaries',
                'tenant_id' => $tenant->id,
                'business_date' => $cursor->toDateString(),
            ], [
                'status' => 'completed',
                'refreshed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $cursor->addDay();
        }

        DB::table('daily_transaction_summaries')->insert([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'business_date' => $start->toDateString(),
            'transaction_count' => 1,
            'unique_receipts' => 1,
            'gross_sales' => 100,
            'net_sales' => 90,
            'vatable_sales' => 80,
            'vat_amount' => 10,
            'sc_vat_exempt_sales' => 0,
            'refund_amount' => 0,
            'promo_with_approval' => 0,
            'promo_without_approval' => 0,
            'employee_discount' => 0,
            'senior_discount' => 0,
            'pwd_discount' => 0,
            'vip_discount' => 0,
            'regular_discount' => 0,
            'other_tax' => $otherTax,
            'service_charge_distributed' => 0,
            'service_charge_retained' => 0,
            'refreshed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function decodeJson(string $output): array
    {
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "Command output was not valid JSON: {$output}");

        return $decoded;
    }

    /**
     * Captures a real, completed pre-backfill snapshot for one tenant over
     * one calendar month via the actual Slice 15 command — not a hand-built
     * fixture row — so the "before" side is exactly what a real run would
     * produce.
     */
    private function captureBeforeSnapshot(string $month): int
    {
        $exit = Artisan::call(self::SNAPSHOT_COMMAND, [
            '--from' => $month.'-01',
            '--to' => Carbon::parse($month.'-01')->addMonthNoOverflow()->toDateString(),
            '--apply' => true,
            '--json' => true,
        ]);
        $result = $this->decodeJson(Artisan::output());
        $this->assertSame(0, $exit, 'Failed to capture before snapshot: '.Artisan::output());

        return $result['run_id'];
    }

    private function callProtected(TaxBackfillMaterialityReport $command, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($command, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($command, $args);
    }

    public function test_compute_delta_and_is_material_pure_logic(): void
    {
        $command = app(TaxBackfillMaterialityReport::class);

        [$deltaAmount, $deltaPercent] = $this->callProtected($command, 'computeDelta', [100.0, 150.0]);
        $this->assertSame(50.0, $deltaAmount);
        $this->assertSame(50.0, $deltaPercent);

        // Divide-by-zero-safe: before == 0 -> percent is null, never infinite.
        [$deltaAmountFromZero, $deltaPercentFromZero] = $this->callProtected($command, 'computeDelta', [0.0, 600.0]);
        $this->assertSame(600.0, $deltaAmountFromZero);
        $this->assertNull($deltaPercentFromZero);

        // Amount-only trigger.
        $this->assertTrue($this->callProtected($command, 'isMaterial', [600.0, 0.1, 500.0, 1.0]));
        // Percent-only trigger.
        $this->assertTrue($this->callProtected($command, 'isMaterial', [10.0, 5.0, 500.0, 1.0]));
        // Both trigger.
        $this->assertTrue($this->callProtected($command, 'isMaterial', [600.0, 5.0, 500.0, 1.0]));
        // Neither triggers.
        $this->assertFalse($this->callProtected($command, 'isMaterial', [10.0, 0.1, 500.0, 1.0]));
        // Null delta (source mismatch / uncompared) never flags, regardless of thresholds.
        $this->assertFalse($this->callProtected($command, 'isMaterial', [null, null, 0.0, 0.0]));
    }

    public function test_refuses_when_explicit_snapshot_run_id_does_not_exist(): void
    {
        $watermark = (int) (TaxBackfillMaterialityRun::max('id') ?? 0);

        $exit = Artisan::call(self::COMMAND, ['--snapshot-run' => 999999999]);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('was not found', Artisan::output());
        $this->assertSame($watermark, (int) (TaxBackfillMaterialityRun::max('id') ?? 0));
    }

    public function test_refuses_when_snapshot_run_is_not_completed(): void
    {
        $running = PreBackfillSnapshotRun::create([
            'snapshot_type' => PreBackfillSnapshotRun::TYPE_PRE_BACKFILL_RENDERED_AGGREGATE,
            'report_contract_version' => PreBackfillSnapshotRun::REPORT_CONTRACT_VERSION_CMSR_V2,
            'window_start' => '2031-11-01',
            'window_end' => '2031-12-01',
            'status' => PreBackfillSnapshotRun::STATUS_RUNNING,
            'tenant_count' => 0,
            'month_count' => 0,
            'forced' => false,
            'started_at' => now(),
        ]);

        $exit = Artisan::call(self::COMMAND, ['--snapshot-run' => $running->id]);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('is not completed', Artisan::output());
        $this->assertSame(0, TaxBackfillMaterialityRun::where('snapshot_run_id', $running->id)->count());
    }

    public function test_apply_computes_correct_delta_and_flags_and_persists_verbatim_after_render(): void
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $this->makeTransaction($tenant, '2031-02-15 04:00:00');

        // Before: daily_transaction_summaries source, other_tax = 50, marked
        // complete BEFORE the "before" snapshot is captured, so before and
        // after resolve to the same source (a genuine same-source compare,
        // not the mismatch scenario covered by a separate test).
        $this->markDailySummaryComplete($tenant, $terminal, '2031-02', 50.0);
        $snapshotRunId = $this->captureBeforeSnapshot('2031-02');

        // After: update the same daily_transaction_summaries row's
        // other_tax to 650 -> a deliberate, large, unambiguous delta of 600
        // (well above the default 500/1% thresholds) so this test cannot be
        // a false negative.
        DB::table('daily_transaction_summaries')
            ->where('tenant_id', $tenant->id)
            ->where('terminal_id', $terminal->id)
            ->where('business_date', '2031-02-01')
            ->update(['other_tax' => 650.0]);

        $exit = Artisan::call(self::COMMAND, [
            '--snapshot-run' => $snapshotRunId,
            '--apply' => true,
            '--json' => true,
        ]);
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(1, $result['summary']['compared']);
        $this->assertSame(0, $result['summary']['source_mismatch']);
        $this->assertSame(0, $result['summary']['contract_mismatch']);
        $this->assertSame(1, $result['summary']['flagged']);

        $row = $result['records'][0];
        $this->assertSame($tenant->id, $row['tenant_id']);
        $this->assertSame('compared', $row['comparison_status']);
        $this->assertEquals(50.0, $row['other_tax_before']);
        $this->assertEquals(650.0, $row['other_tax_after']);
        $this->assertEquals(600.0, $row['other_tax_delta_amount']);
        $this->assertTrue($row['materiality_flag']);

        $record = TaxBackfillMaterialityRecord::where('run_id', $result['materiality_run_id'])->first();
        $oracle = app(SalesReportDataService::class)->getCmsrReportData(
            SalesReportFilter::forTenantYearMonth($tenant->id, 2031, 2)
        );
        $this->assertSame($oracle->source, $record->after_source);

        // Compare everything except generated_at: the daily_summary path
        // stamps it with now()->toIso8601String() at call time, so the
        // command's internal call and this test's separate oracle call can
        // legitimately land on different seconds -- a real (if rare) flake
        // unrelated to the persistence logic under test.
        $oracleArray = $oracle->toArray();
        $persisted = $record->after_rendered_result;
        $this->assertArrayHasKey('generated_at', $oracleArray);
        $this->assertArrayHasKey('generated_at', $persisted);
        unset($oracleArray['generated_at'], $persisted['generated_at']);
        $this->assertEquals($oracleArray, $persisted);
    }

    public function test_source_mismatch_pair_is_quarantined_not_treated_as_a_failure_and_other_pairs_still_process(): void
    {
        $tenantA = Tenant::factory()->create();
        $terminalA = PosTerminal::factory()->create(['tenant_id' => $tenantA->id]);
        $this->makeTransaction($tenantA, '2031-03-10 04:00:00');

        $tenantB = Tenant::factory()->create();
        $this->makeTransaction($tenantB, '2031-03-12 04:00:00');

        // Both tenants captured "before" as raw_transactions (no daily
        // summary rows exist for either yet at capture time).
        $exit = Artisan::call(self::SNAPSHOT_COMMAND, [
            '--from' => '2031-03-01',
            '--to' => '2031-04-01',
            '--apply' => true,
            '--json' => true,
        ]);
        $snapshot = $this->decodeJson(Artisan::output());
        $this->assertSame(0, $exit);
        $this->assertSame(2, $snapshot['captured_this_run']);

        // Only tenant A flips to daily_summary source before "after" is
        // captured -- a genuine, real mismatch for A only.
        $this->markDailySummaryComplete($tenantA, $terminalA, '2031-03', 50.0);

        $exit = Artisan::call(self::COMMAND, [
            '--snapshot-run' => $snapshot['run_id'],
            '--apply' => true,
            '--json' => true,
        ]);
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertNull($result['capture_stats']['failed_pairs'] ?? null ?: null);
        $this->assertSame(0, $result['capture_stats']['failed_this_run']);
        $this->assertSame(2, $result['capture_stats']['captured_this_run']);
        $this->assertSame(1, $result['summary']['source_mismatch']);
        $this->assertSame(0, $result['summary']['contract_mismatch']);
        $this->assertSame(1, $result['summary']['compared']);

        $recordA = TaxBackfillMaterialityRecord::query()
            ->where('run_id', $result['materiality_run_id'])
            ->where('tenant_id', $tenantA->id)
            ->first();
        $this->assertSame('source_mismatch', $recordA->comparison_status);
        $this->assertNull($recordA->other_tax_delta_amount);
        $this->assertSame('raw_transactions', $recordA->before_source);
        $this->assertSame('daily_transaction_summaries', $recordA->after_source);

        $recordB = TaxBackfillMaterialityRecord::query()
            ->where('run_id', $result['materiality_run_id'])
            ->where('tenant_id', $tenantB->id)
            ->first();
        $this->assertSame('compared', $recordB->comparison_status);
    }

    public function test_v1_snapshot_pair_is_recorded_as_contract_mismatch_not_silently_compared(): void
    {
        $tenant = Tenant::factory()->create();
        $this->makeTransaction($tenant, '2031-12-15 04:00:00');

        $snapshotRun = PreBackfillSnapshotRun::create([
            'snapshot_type' => PreBackfillSnapshotRun::TYPE_PRE_BACKFILL_RENDERED_AGGREGATE,
            'report_contract_version' => PreBackfillSnapshotRun::REPORT_CONTRACT_VERSION_CMSR_V1,
            'window_start' => '2031-12-01',
            'window_end' => '2032-01-01',
            'status' => PreBackfillSnapshotRun::STATUS_COMPLETED,
            'tenant_count' => 1,
            'month_count' => 1,
            'forced' => false,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        PreBackfillSnapshotRecord::create([
            'run_id' => $snapshotRun->id,
            'tenant_id' => $tenant->id,
            'reporting_year' => 2031,
            'reporting_month' => 12,
            'source' => 'raw_transactions',
            'rendered_result' => [
                'status' => 'success',
                'totals' => ['other_tax' => 50.0],
                'source' => 'raw_transactions',
            ],
            'captured_at' => now(),
        ]);

        $exit = Artisan::call(self::COMMAND, [
            '--snapshot-run' => $snapshotRun->id,
            '--apply' => true,
            '--json' => true,
        ]);
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(0, $result['summary']['compared']);
        $this->assertSame(0, $result['summary']['source_mismatch']);
        $this->assertSame(1, $result['summary']['contract_mismatch']);
        $this->assertSame(1, $result['capture_stats']['captured_this_run']);

        $record = TaxBackfillMaterialityRecord::where('run_id', $result['materiality_run_id'])->first();
        $this->assertSame(TaxBackfillMaterialityRecord::COMPARISON_STATUS_CONTRACT_MISMATCH, $record->comparison_status);
        $this->assertNull($record->other_tax_before);
        $this->assertNull($record->other_tax_after);
        $this->assertNull($record->other_tax_delta_amount);
        $this->assertNull($record->other_tax_delta_percent);
        $this->assertIsArray($record->after_rendered_result);
    }

    public function test_apply_refuses_without_force_when_completed_run_exists_and_force_creates_independent_run(): void
    {
        $tenant = Tenant::factory()->create();
        $this->makeTransaction($tenant, '2031-04-15 04:00:00');
        $snapshotRunId = $this->captureBeforeSnapshot('2031-04');

        $first = Artisan::call(self::COMMAND, ['--snapshot-run' => $snapshotRunId, '--apply' => true, '--json' => true]);
        $firstResult = $this->decodeJson(Artisan::output());
        $this->assertSame(0, $first);

        $second = Artisan::call(self::COMMAND, ['--snapshot-run' => $snapshotRunId, '--apply' => true]);
        $this->assertNotEquals(0, $second);
        $this->assertStringContainsString('REFUSED', Artisan::output());

        $third = Artisan::call(self::COMMAND, ['--snapshot-run' => $snapshotRunId, '--apply' => true, '--force' => true, '--json' => true]);
        $thirdResult = $this->decodeJson(Artisan::output());
        $this->assertSame(0, $third);
        $this->assertNotSame($firstResult['materiality_run_id'], $thirdResult['materiality_run_id']);

        $this->assertSame(2, TaxBackfillMaterialityRun::where('snapshot_run_id', $snapshotRunId)->count());
    }

    public function test_bare_read_of_completed_run_reevaluates_thresholds_without_any_new_capture_or_write(): void
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $this->makeTransaction($tenant, '2031-05-15 04:00:00');

        $this->markDailySummaryComplete($tenant, $terminal, '2031-05', 1000.0);
        $snapshotRunId = $this->captureBeforeSnapshot('2031-05');

        // Delta of 5 (0.5%) -- below the default 500/1% thresholds. Same
        // source (daily_transaction_summaries) on both sides.
        DB::table('daily_transaction_summaries')
            ->where('tenant_id', $tenant->id)
            ->where('terminal_id', $terminal->id)
            ->where('business_date', '2031-05-01')
            ->update(['other_tax' => 1005.0]);

        $exit = Artisan::call(self::COMMAND, ['--snapshot-run' => $snapshotRunId, '--apply' => true, '--json' => true]);
        $applyResult = $this->decodeJson(Artisan::output());
        $this->assertSame(0, $exit);
        $this->assertSame(0, $applyResult['summary']['flagged']);

        $recordCountAfterApply = TaxBackfillMaterialityRecord::count();

        // Re-read with a much lower threshold -- should now flag the same
        // pair, purely from re-evaluating the already-persisted delta.
        $exit2 = Artisan::call(self::COMMAND, [
            '--snapshot-run' => $snapshotRunId,
            '--threshold-amount' => 1,
            '--threshold-percent' => 0.01,
            '--json' => true,
        ]);
        $readResult = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit2);
        $this->assertSame('read', $readResult['mode']);
        $this->assertSame(1, $readResult['summary']['flagged']);
        $this->assertSame($applyResult['materiality_run_id'], $readResult['materiality_run_id']);
        $this->assertSame($recordCountAfterApply, TaxBackfillMaterialityRecord::count());
    }

    public function test_dry_run_before_any_capture_never_calls_the_report_path_and_writes_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        $this->makeTransaction($tenant, '2031-06-15 04:00:00');
        $snapshotRunId = $this->captureBeforeSnapshot('2031-06');

        $runWatermark = (int) (TaxBackfillMaterialityRun::max('id') ?? 0);
        $recordWatermark = (int) (TaxBackfillMaterialityRecord::max('id') ?? 0);

        $exit = Artisan::call(self::COMMAND, ['--snapshot-run' => $snapshotRunId, '--json' => true]);
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame('dry-run', $result['mode']);
        $this->assertSame(1, $result['population']);
        $this->assertSame($runWatermark, (int) (TaxBackfillMaterialityRun::max('id') ?? 0));
        $this->assertSame($recordWatermark, (int) (TaxBackfillMaterialityRecord::max('id') ?? 0));
    }

    /**
     * Also exercises extractOtherTax()'s malformed-data guard: a rendered
     * result missing its own other_tax key must be a failed pair (caught,
     * logged, run marked failed), never silently treated as a zero delta.
     */
    public function test_apply_resume_captures_only_the_pending_pair_and_displays_the_full_merged_run(): void
    {
        $tenantA = Tenant::factory()->create();
        $terminalA = PosTerminal::factory()->create(['tenant_id' => $tenantA->id]);
        $this->makeTransaction($tenantA, '2031-07-10 04:00:00');

        $tenantB = Tenant::factory()->create();
        $terminalB = PosTerminal::factory()->create(['tenant_id' => $tenantB->id]);
        $this->makeTransaction($tenantB, '2031-07-12 04:00:00');

        $this->markDailySummaryComplete($tenantA, $terminalA, '2031-07', 100.0);
        $this->markDailySummaryComplete($tenantB, $terminalB, '2031-07', 200.0);

        $exit = Artisan::call(self::SNAPSHOT_COMMAND, [
            '--from' => '2031-07-01',
            '--to' => '2031-08-01',
            '--apply' => true,
            '--json' => true,
        ]);
        $snapshot = $this->decodeJson(Artisan::output());
        $this->assertSame(0, $exit);
        $this->assertSame(2, $snapshot['captured_this_run']);

        // Corrupt tenant B's captured "before" rendered_result so its own
        // comparison throws (missing other_tax) -- a real, non-mocked
        // failure, exercising extractOtherTax()'s guard and the resume path
        // together.
        $beforeRecordB = PreBackfillSnapshotRecord::where('run_id', $snapshot['run_id'])
            ->where('tenant_id', $tenantB->id)
            ->first();
        $original = $beforeRecordB->rendered_result;
        $corrupted = $original;
        unset($corrupted['totals']['other_tax']);
        DB::table('pre_backfill_snapshot_records')
            ->where('id', $beforeRecordB->id)
            ->update(['rendered_result' => json_encode($corrupted)]);

        $firstApply = Artisan::call(self::COMMAND, [
            '--snapshot-run' => $snapshot['run_id'],
            '--apply' => true,
            '--json' => true,
        ]);
        $firstResult = $this->decodeJson(Artisan::output());

        $this->assertNotEquals(0, $firstApply);
        $this->assertSame(1, $firstResult['capture_stats']['captured_this_run']);
        $this->assertSame(1, $firstResult['capture_stats']['failed_this_run']);
        $this->assertSame($tenantB->id, $firstResult['capture_stats']['failed_pairs'][0]['tenant_id']);
        $this->assertSame('failed', $firstResult['materiality_run_status']);
        $this->assertSame(1, TaxBackfillMaterialityRecord::where('run_id', $firstResult['materiality_run_id'])->count());

        // Fix the corruption, then resume -- no --force needed, status is
        // 'failed', not 'completed'.
        DB::table('pre_backfill_snapshot_records')
            ->where('id', $beforeRecordB->id)
            ->update(['rendered_result' => json_encode($original)]);

        $secondApply = Artisan::call(self::COMMAND, [
            '--snapshot-run' => $snapshot['run_id'],
            '--apply' => true,
            '--json' => true,
        ]);
        $secondResult = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $secondApply, Artisan::output());
        $this->assertSame($firstResult['materiality_run_id'], $secondResult['materiality_run_id']);
        $this->assertSame(1, $secondResult['capture_stats']['captured_this_run']);
        $this->assertSame(0, $secondResult['capture_stats']['failed_this_run']);
        $this->assertSame('completed', $secondResult['materiality_run_status']);

        // The final display must include BOTH tenants -- the one captured
        // on the first (failed) attempt AND the one captured on resume --
        // not just the newly captured pair.
        $this->assertSame(2, $secondResult['summary']['compared'] + $secondResult['summary']['source_mismatch'] + $secondResult['summary']['contract_mismatch']);
        $this->assertSame(2, TaxBackfillMaterialityRecord::where('run_id', $secondResult['materiality_run_id'])->count());
        $tenantIdsInResult = array_column($secondResult['records'], 'tenant_id');
        $this->assertContains($tenantA->id, $tenantIdsInResult);
        $this->assertContains($tenantB->id, $tenantIdsInResult);
    }
}

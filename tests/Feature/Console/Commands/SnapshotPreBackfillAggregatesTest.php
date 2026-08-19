<?php

namespace Tests\Feature\Console\Commands;

use App\Models\PosTerminal;
use App\Models\PreBackfillSnapshotRecord;
use App\Models\PreBackfillSnapshotRun;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Reports\SalesReportDataService;
use App\Services\Reports\SalesReportFilter;
use App\Services\Reports\SalesReportResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes, Slice 15 (T073/T074). See
 * specs/002-backfill-transaction-taxes/slice-15-pre-backfill-snapshot-brief.md,
 * "Test plan".
 *
 * Covers `transactions:snapshot-pre-backfill-aggregates`: successful
 * capture against the real report path (with an independently-called oracle
 * for comparison), durable persistence, source-label recording (not
 * re-derivation), the refuse/--force guard, key isolation across
 * snapshot_type/report_contract_version, resumability (spy-verified call
 * count), per-pair failure isolation, zero mutation of
 * transaction_taxes/transactions, and CLI-level validation/output parity.
 *
 * Known test-isolation issue (tests/TestCase.php:38 breaks RefreshDatabase,
 * see tests/TestCase.php:38 and this feature's other test files): rows can
 * leak between test methods within the same run. Because this command's
 * idempotency key is the (snapshot_type, window, report_contract_version)
 * tuple, every test below uses its own dedicated, never-reused --from/--to
 * window (a distinct calendar month per test, all in year 2030) so that
 * leaked rows from one test can never collide with another test's
 * assumption of "no prior run for this window" — the same discipline this
 * feature's other tests apply via tenant-id/run-id scoping, adapted to this
 * command's window-keyed idempotency.
 */
class SnapshotPreBackfillAggregatesTest extends TestCase
{
    private const COMMAND = 'transactions:snapshot-pre-backfill-aggregates';

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

    /**
     * Inserts report_refresh_states rows for every calendar day of $month
     * (a 'Y-m' string) plus one daily_transaction_summaries row, which is
     * exactly what SalesReportDataService::dailySummaryResult() requires to
     * return non-null (complete refresh coverage AND non-empty rows) — see
     * app/Services/Reports/SalesReportDataService.php:102-142.
     */
    private function markDailySummaryComplete(Tenant $tenant, PosTerminal $terminal, string $month): void
    {
        $start = Carbon::parse($month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            DB::table('report_refresh_states')->insert([
                'report_type' => 'daily_transaction_summaries',
                'tenant_id' => $tenant->id,
                'business_date' => $cursor->toDateString(),
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
            'other_tax' => 5,
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

    public function test_from_and_to_are_required(): void
    {
        $exit = Artisan::call(self::COMMAND, ['--to' => '2030-01-01']);
        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('--from is required', Artisan::output());

        $exit = Artisan::call(self::COMMAND, ['--from' => '2030-01-01']);
        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('--to is required', Artisan::output());
    }

    public function test_empty_window_is_rejected_before_any_run_is_created(): void
    {
        $watermark = (int) (PreBackfillSnapshotRun::max('id') ?? 0);

        $exit = Artisan::call(self::COMMAND, [
            '--from' => '2030-01-10',
            '--to' => '2030-01-10',
        ]);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('resolve to an empty window', Artisan::output());
        $this->assertSame($watermark, (int) (PreBackfillSnapshotRun::max('id') ?? 0));
    }

    public function test_apply_captures_every_pair_and_matches_independently_called_report_data_as_oracle(): void
    {
        $tenant = Tenant::factory()->create();
        $this->makeTransaction($tenant, '2030-02-15 04:00:00');

        $exit = Artisan::call(self::COMMAND, [
            '--from' => '2030-02-01',
            '--to' => '2030-03-01',
            '--apply' => true,
            '--json' => true,
        ]);

        $result = $this->decodeJson(Artisan::output());
        $this->assertSame(0, $exit);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, $result['captured_this_run']);
        $this->assertSame(0, $result['failed_this_run']);

        $runId = $result['run_id'];

        $record = PreBackfillSnapshotRecord::where('run_id', $runId)
            ->where('tenant_id', $tenant->id)
            ->where('reporting_year', 2030)
            ->where('reporting_month', 2)
            ->first();

        $this->assertNotNull($record);

        // Oracle: call the real report path independently, exactly as the
        // command's own capturePending() does, and assert byte-for-byte
        // parity with what was persisted.
        $oracle = app(SalesReportDataService::class)->getCmsrReportData(
            SalesReportFilter::forTenantYearMonth($tenant->id, 2030, 2)
        );

        $this->assertSame($oracle->source, $record->source);
        $this->assertEquals($oracle->toArray(), $record->rendered_result);
    }

    public function test_completed_run_records_are_durably_queryable_via_direct_db_reads(): void
    {
        $tenant = Tenant::factory()->create();
        $this->makeTransaction($tenant, '2030-03-15 04:00:00');

        $exit = Artisan::call(self::COMMAND, [
            '--from' => '2030-03-01',
            '--to' => '2030-04-01',
            '--apply' => true,
            '--json' => true,
        ]);
        $result = $this->decodeJson(Artisan::output());
        $this->assertSame(0, $exit);

        $rows = DB::table('pre_backfill_snapshot_records')
            ->where('run_id', $result['run_id'])
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($tenant->id, (int) $rows->first()->tenant_id);
        $decoded = json_decode($rows->first()->rendered_result, true);
        $this->assertSame('success', $decoded['status']);

        $runRow = DB::table('pre_backfill_snapshot_runs')->where('id', $result['run_id'])->first();
        $this->assertSame('completed', $runRow->status);
        $this->assertSame(PreBackfillSnapshotRun::TYPE_PRE_BACKFILL_RENDERED_AGGREGATE, $runRow->snapshot_type);
        $this->assertSame(PreBackfillSnapshotRun::REPORT_CONTRACT_VERSION_CMSR_V2, $runRow->report_contract_version);
    }

    public function test_source_label_reflects_daily_summary_or_raw_transactions_per_pair_without_rederiving(): void
    {
        $tenantSummary = Tenant::factory()->create();
        $terminalSummary = PosTerminal::factory()->create(['tenant_id' => $tenantSummary->id]);
        $this->markDailySummaryComplete($tenantSummary, $terminalSummary, '2030-04');
        // A transactions row is still required so this tenant is part of
        // the enumerated population at all (Design decision 4 derives
        // tenants from `transactions`, not from daily_transaction_summaries)
        // — but dailySummaryResult() short-circuits before the raw path is
        // ever built, so its content doesn't matter for this tenant/month.
        $this->makeTransaction($tenantSummary, '2030-04-15 04:00:00');

        $tenantRaw = Tenant::factory()->create();
        $this->makeTransaction($tenantRaw, '2030-04-15 04:00:00');

        $exit = Artisan::call(self::COMMAND, [
            '--from' => '2030-04-01',
            '--to' => '2030-05-01',
            '--apply' => true,
            '--json' => true,
        ]);
        $result = $this->decodeJson(Artisan::output());
        $this->assertSame(0, $exit);

        $summaryRecord = PreBackfillSnapshotRecord::where('run_id', $result['run_id'])
            ->where('tenant_id', $tenantSummary->id)->first();
        $rawRecord = PreBackfillSnapshotRecord::where('run_id', $result['run_id'])
            ->where('tenant_id', $tenantRaw->id)->first();

        $this->assertNotNull($summaryRecord);
        $this->assertNotNull($rawRecord);
        $this->assertSame('daily_transaction_summaries', $summaryRecord->source);
        $this->assertSame('raw_transactions', $rawRecord->source);
    }

    public function test_completed_run_refuses_bare_reinvocation_but_force_creates_a_distinct_new_run(): void
    {
        $tenant = Tenant::factory()->create();
        $this->makeTransaction($tenant, '2030-05-15 04:00:00');

        $firstExit = Artisan::call(self::COMMAND, [
            '--from' => '2030-05-01',
            '--to' => '2030-06-01',
            '--apply' => true,
            '--json' => true,
        ]);
        $first = $this->decodeJson(Artisan::output());
        $this->assertSame(0, $firstExit);
        $this->assertSame('completed', $first['status']);

        $watermark = (int) PreBackfillSnapshotRecord::max('id');

        $refusedExit = Artisan::call(self::COMMAND, [
            '--from' => '2030-05-01',
            '--to' => '2030-06-01',
            '--apply' => true,
            '--json' => true,
        ]);
        $refused = $this->decodeJson(Artisan::output());

        $this->assertNotEquals(0, $refusedExit);
        $this->assertTrue($refused['refused']);
        $this->assertSame($first['run_id'], $refused['existing_run']['id']);
        $this->assertSame($watermark, (int) PreBackfillSnapshotRecord::max('id'), 'Refused invocation must write zero new records.');
        $this->assertSame(1, PreBackfillSnapshotRun::where('window_start', '2030-05-01 00:00:00')->count());

        $forcedExit = Artisan::call(self::COMMAND, [
            '--from' => '2030-05-01',
            '--to' => '2030-06-01',
            '--apply' => true,
            '--force' => true,
            '--json' => true,
        ]);
        $forced = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $forcedExit);
        $this->assertNotEquals($first['run_id'], $forced['run_id']);
        $this->assertTrue($forced['forced']);

        // The original run and its records are untouched.
        $original = PreBackfillSnapshotRun::find($first['run_id']);
        $this->assertSame('completed', $original->status);
        $this->assertSame(1, PreBackfillSnapshotRecord::where('run_id', $first['run_id'])->count());
        $this->assertSame(1, PreBackfillSnapshotRecord::where('run_id', $forced['run_id'])->count());
    }

    public function test_different_snapshot_type_or_report_contract_version_does_not_block_an_identical_window(): void
    {
        $tenant = Tenant::factory()->create();
        $this->makeTransaction($tenant, '2030-06-15 04:00:00');

        // Directly seed a completed run for the identical window under a
        // DIFFERENT snapshot_type/report_contract_version pair — something
        // the CLI itself can never produce (both are fixed constants), but
        // exactly what the unique index and lookup logic must not collide
        // with.
        $foreignRun = PreBackfillSnapshotRun::create([
            'snapshot_type' => 'some_other_snapshot_purpose',
            'report_contract_version' => 'cmsr_v2',
            'window_start' => '2030-06-01 00:00:00',
            'window_end' => '2030-07-01 00:00:00',
            'status' => PreBackfillSnapshotRun::STATUS_COMPLETED,
            'tenant_count' => 1,
            'month_count' => 1,
            'forced' => false,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // Give the foreign run its own record, so "coexist independently"
        // below is a real assertion, not just an absence of interference.
        PreBackfillSnapshotRecord::create([
            'run_id' => $foreignRun->id,
            'tenant_id' => $tenant->id,
            'reporting_year' => 2030,
            'reporting_month' => 6,
            'source' => 'raw_transactions',
            'rendered_result' => ['status' => 'success', 'note' => 'foreign snapshot_type/version'],
            'captured_at' => now(),
        ]);

        $exit = Artisan::call(self::COMMAND, [
            '--from' => '2030-06-01',
            '--to' => '2030-07-01',
            '--apply' => true,
            '--json' => true,
        ]);
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertFalse($result['refused']);
        $this->assertSame('create', $result['action']);
        $this->assertNotSame($foreignRun->id, $result['run_id']);

        // Both runs' records coexist independently: the foreign run's
        // pre-existing record is untouched, and the real run captured its
        // own, under a different run_id.
        $this->assertSame(1, PreBackfillSnapshotRecord::where('run_id', $foreignRun->id)->count());
        $this->assertSame(1, PreBackfillSnapshotRecord::where('run_id', $result['run_id'])->count());
    }

    public function test_resume_only_calls_report_service_for_missing_pairs_and_reaches_completed(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $this->makeTransaction($tenantA, '2030-07-15 04:00:00');
        $this->makeTransaction($tenantB, '2030-07-15 04:00:00');

        $run = PreBackfillSnapshotRun::create([
            'snapshot_type' => PreBackfillSnapshotRun::TYPE_PRE_BACKFILL_RENDERED_AGGREGATE,
            'report_contract_version' => PreBackfillSnapshotRun::REPORT_CONTRACT_VERSION_CMSR_V2,
            'window_start' => '2030-07-01 00:00:00',
            'window_end' => '2030-08-01 00:00:00',
            'status' => PreBackfillSnapshotRun::STATUS_FAILED,
            'tenant_count' => 2,
            'month_count' => 1,
            'forced' => false,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // Simulate a partial prior capture: tenant A already has a record.
        PreBackfillSnapshotRecord::create([
            'run_id' => $run->id,
            'tenant_id' => $tenantA->id,
            'reporting_year' => 2030,
            'reporting_month' => 7,
            'source' => 'raw_transactions',
            'rendered_result' => ['status' => 'success'],
            'captured_at' => now(),
        ]);

        $this->mock(SalesReportDataService::class, function ($mock) {
            $mock->shouldReceive('getCmsrReportData')
                ->once()
                ->andReturn(new SalesReportResult(2030, '07', [], ['totals' => 'stub'], 'raw_transactions'));
        });

        $exit = Artisan::call(self::COMMAND, [
            '--from' => '2030-07-01',
            '--to' => '2030-08-01',
            '--apply' => true,
            '--json' => true,
        ]);
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame('resume', $result['action']);
        $this->assertSame($run->id, $result['run_id']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, $result['captured_this_run']);
        $this->assertSame(0, $result['failed_this_run']);
        $this->assertSame(2, PreBackfillSnapshotRecord::where('run_id', $run->id)->count());
    }

    public function test_per_pair_failure_is_isolated_and_run_ends_failed(): void
    {
        $tenantGood = Tenant::factory()->create();
        $tenantBad = Tenant::factory()->create();
        $this->makeTransaction($tenantGood, '2030-08-15 04:00:00');
        $this->makeTransaction($tenantBad, '2030-08-15 04:00:00');

        $this->mock(SalesReportDataService::class, function ($mock) use ($tenantBad) {
            $mock->shouldReceive('getCmsrReportData')
                ->twice()
                ->andReturnUsing(function (SalesReportFilter $filter) use ($tenantBad) {
                    if ((int) $filter->tenantId === $tenantBad->id) {
                        throw new \RuntimeException('simulated report failure');
                    }

                    return new SalesReportResult((int) $filter->year(), $filter->month(), [], ['totals' => 'ok'], 'raw_transactions');
                });
        });

        $exit = Artisan::call(self::COMMAND, [
            '--from' => '2030-08-01',
            '--to' => '2030-09-01',
            '--apply' => true,
            '--json' => true,
        ]);
        $result = $this->decodeJson(Artisan::output());

        $this->assertNotEquals(0, $exit);
        $this->assertSame('failed', $result['status']);
        $this->assertSame(1, $result['captured_this_run']);
        $this->assertSame(1, $result['failed_this_run']);

        $this->assertSame(1, PreBackfillSnapshotRecord::where('run_id', $result['run_id'])
            ->where('tenant_id', $tenantGood->id)->count());
        $this->assertSame(0, PreBackfillSnapshotRecord::where('run_id', $result['run_id'])
            ->where('tenant_id', $tenantBad->id)->count());

        // Finding 2 (Slice 15 review): the failed pair's identity must be
        // recoverable from the persisted/returned result itself, not only
        // from Log::warning — an operator querying/inspecting output
        // shouldn't have to grep logs to know which pair to retry.
        $this->assertArrayHasKey('failed_pairs', $result);
        $this->assertCount(1, $result['failed_pairs']);
        $this->assertSame($tenantBad->id, $result['failed_pairs'][0]['tenant_id']);
        $this->assertSame(2030, $result['failed_pairs'][0]['year']);
        $this->assertSame(8, $result['failed_pairs'][0]['month']);
    }

    public function test_dry_run_never_calls_the_report_path(): void
    {
        $tenant = Tenant::factory()->create();
        $this->makeTransaction($tenant, '2030-09-15 04:00:00');

        $this->mock(SalesReportDataService::class, function ($mock) {
            $mock->shouldReceive('getCmsrReportData')->never();
        });

        $exit = Artisan::call(self::COMMAND, [
            '--from' => '2030-09-01',
            '--to' => '2030-10-01',
            '--json' => true,
        ]);
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame('dry-run', $result['mode']);
        $this->assertSame('create', $result['action']);
        $this->assertSame(1, $result['population']['pair_count']);
        $this->assertSame(1, $result['pending']);

        $this->assertSame(
            0,
            PreBackfillSnapshotRun::where('window_start', '2030-09-01 00:00:00')->count()
        );
    }

    public function test_zero_writes_to_transaction_taxes_and_transactions_for_dry_run_and_apply(): void
    {
        $tenant = Tenant::factory()->create();
        $this->makeTransaction($tenant, '2030-10-15 04:00:00');

        $transactionsBefore = DB::table('transactions')->count();
        $taxesBefore = DB::table('transaction_taxes')->count();

        Artisan::call(self::COMMAND, [
            '--from' => '2030-10-01',
            '--to' => '2030-11-01',
        ]);

        $this->assertSame($transactionsBefore, DB::table('transactions')->count());
        $this->assertSame($taxesBefore, DB::table('transaction_taxes')->count());

        Artisan::call(self::COMMAND, [
            '--from' => '2030-10-01',
            '--to' => '2030-11-01',
            '--apply' => true,
        ]);

        $this->assertSame($transactionsBefore, DB::table('transactions')->count());
        $this->assertSame($taxesBefore, DB::table('transaction_taxes')->count());
    }

    public function test_human_and_json_output_agree_structurally(): void
    {
        $tenant = Tenant::factory()->create();
        $this->makeTransaction($tenant, '2030-11-15 04:00:00');

        Artisan::call(self::COMMAND, [
            '--from' => '2030-11-01',
            '--to' => '2030-12-01',
            '--apply' => true,
            '--json' => true,
        ]);
        $jsonResult = $this->decodeJson(Artisan::output());

        $this->assertSame('completed', $jsonResult['status']);
        $this->assertGreaterThan(0, $jsonResult['run_id']);

        Artisan::call(self::COMMAND, [
            '--from' => '2030-12-01',
            '--to' => '2031-01-01',
            '--json' => false,
        ]);
        $humanOutput = Artisan::output();

        $this->assertStringContainsString('Pre-backfill snapshot (dry-run)', $humanOutput);
        $this->assertStringContainsString('Tenants', $humanOutput);
    }

    /**
     * Finding 1 (Slice 15 review): resolveRunDecision() + createRun() was an
     * unlocked lookup-then-insert — two concurrent invocations for the
     * identical (snapshot_type, window, report_contract_version) key could
     * both observe "no run exists" and both create independent runs. This
     * proves the fix actually serializes access: the lock is acquired
     * manually here (simulating a concurrent invocation already holding it),
     * the command is asserted to fail fast without creating any run row
     * while the lock is held, and — after releasing — a normal invocation
     * is asserted to succeed, proving the earlier failure was the lock
     * doing its job and not some unrelated breakage.
     */
    public function test_concurrent_invocation_for_same_key_is_serialized_and_fails_fast(): void
    {
        $tenant = Tenant::factory()->create();
        $this->makeTransaction($tenant, '2031-01-15 04:00:00');

        $windowStart = Carbon::createFromFormat('Y-m-d', '2031-01-01')->startOfDay();
        $windowEnd = Carbon::createFromFormat('Y-m-d', '2031-02-01')->startOfDay();

        $lockKey = sprintf(
            'pre-backfill-snapshot:%s:%d:%d:%s',
            PreBackfillSnapshotRun::TYPE_PRE_BACKFILL_RENDERED_AGGREGATE,
            $windowStart->timestamp,
            $windowEnd->timestamp,
            PreBackfillSnapshotRun::REPORT_CONTRACT_VERSION_CMSR_V2
        );

        $lock = Cache::lock($lockKey, 10);
        $this->assertTrue($lock->get(), 'Test setup failed to acquire the idempotency-key lock ahead of the command under test.');

        try {
            $exit = Artisan::call(self::COMMAND, [
                '--from' => '2031-01-01',
                '--to' => '2031-02-01',
                '--apply' => true,
                '--json' => true,
            ]);
            $output = Artisan::output();

            $this->assertNotEquals(0, $exit, 'The command must fail fast while another invocation holds the lock for the identical key.');
            $this->assertStringContainsString('Another invocation appears to be in progress', $output);
            $this->assertSame(
                0,
                PreBackfillSnapshotRun::where('window_start', '2031-01-01 00:00:00')->count(),
                'No run row should be created while the command is blocked out by the lock.'
            );
        } finally {
            $lock->release();
        }

        // Lock released — a normal invocation for the identical key must now
        // proceed and reach completed, proving the earlier failure was the
        // lock serializing access, not the command being broken outright.
        $secondExit = Artisan::call(self::COMMAND, [
            '--from' => '2031-01-01',
            '--to' => '2031-02-01',
            '--apply' => true,
            '--json' => true,
        ]);
        $second = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $secondExit);
        $this->assertSame('completed', $second['status']);
        $this->assertSame(1, PreBackfillSnapshotRun::where('window_start', '2031-01-01 00:00:00')->count());
    }

    /**
     * Finding 3 (Slice 15 review): --force correctly has no effect when the
     * decision is 'resume' (a running/failed run is always resumed
     * regardless of the flag) — but that must be surfaced explicitly so it
     * doesn't read as a bug silently swallowing the flag. This scenario
     * (force=true, decision=create) must NOT show the note.
     */
    public function test_force_note_is_absent_when_force_creates_a_new_run(): void
    {
        $tenant = Tenant::factory()->create();
        $this->makeTransaction($tenant, '2031-03-15 04:00:00');

        Artisan::call(self::COMMAND, [
            '--from' => '2031-03-01',
            '--to' => '2031-04-01',
            '--force' => true,
        ]);
        $this->assertStringNotContainsString('--force had no effect', Artisan::output());

        $exit = Artisan::call(self::COMMAND, [
            '--from' => '2031-03-01',
            '--to' => '2031-04-01',
            '--apply' => true,
            '--force' => true,
            '--json' => true,
        ]);
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame('create', $result['action']);
        $this->assertArrayHasKey('force_note', $result);
        $this->assertNull($result['force_note']);
    }

    /**
     * Finding 3 (Slice 15 review): this is the scenario the note exists
     * for — force=true, but the decision made was 'resume' (an existing
     * running/failed run for this key), so the flag had no effect.
     */
    public function test_force_note_appears_when_force_resumes_an_existing_run(): void
    {
        $tenant = Tenant::factory()->create();
        $this->makeTransaction($tenant, '2031-04-15 04:00:00');

        PreBackfillSnapshotRun::create([
            'snapshot_type' => PreBackfillSnapshotRun::TYPE_PRE_BACKFILL_RENDERED_AGGREGATE,
            'report_contract_version' => PreBackfillSnapshotRun::REPORT_CONTRACT_VERSION_CMSR_V2,
            'window_start' => '2031-04-01 00:00:00',
            'window_end' => '2031-05-01 00:00:00',
            'status' => PreBackfillSnapshotRun::STATUS_FAILED,
            'tenant_count' => 1,
            'month_count' => 1,
            'forced' => false,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // Human-mode dry-run first — read-only, doesn't consume the seeded
        // run — to confirm the note actually renders in printed output, not
        // only as a JSON field.
        Artisan::call(self::COMMAND, [
            '--from' => '2031-04-01',
            '--to' => '2031-05-01',
            '--force' => true,
        ]);
        $this->assertStringContainsString('--force had no effect', Artisan::output());

        $exit = Artisan::call(self::COMMAND, [
            '--from' => '2031-04-01',
            '--to' => '2031-05-01',
            '--apply' => true,
            '--force' => true,
            '--json' => true,
        ]);
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame('resume', $result['action']);
        $this->assertSame(
            '--force had no effect: an existing running/failed run was resumed instead.',
            $result['force_note']
        );
    }

    /**
     * Finding 3 (Slice 15 review): without --force at all, the note must
     * never appear, even for a decision that resolves to 'resume'.
     */
    public function test_force_note_is_absent_when_force_flag_not_passed(): void
    {
        $tenant = Tenant::factory()->create();
        $this->makeTransaction($tenant, '2031-05-15 04:00:00');

        PreBackfillSnapshotRun::create([
            'snapshot_type' => PreBackfillSnapshotRun::TYPE_PRE_BACKFILL_RENDERED_AGGREGATE,
            'report_contract_version' => PreBackfillSnapshotRun::REPORT_CONTRACT_VERSION_CMSR_V2,
            'window_start' => '2031-05-01 00:00:00',
            'window_end' => '2031-06-01 00:00:00',
            'status' => PreBackfillSnapshotRun::STATUS_FAILED,
            'tenant_count' => 1,
            'month_count' => 1,
            'forced' => false,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        Artisan::call(self::COMMAND, [
            '--from' => '2031-05-01',
            '--to' => '2031-06-01',
        ]);
        $this->assertStringNotContainsString('--force had no effect', Artisan::output());

        $exit = Artisan::call(self::COMMAND, [
            '--from' => '2031-05-01',
            '--to' => '2031-06-01',
            '--apply' => true,
            '--json' => true,
        ]);
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame('resume', $result['action']);
        $this->assertNull($result['force_note']);
    }
}

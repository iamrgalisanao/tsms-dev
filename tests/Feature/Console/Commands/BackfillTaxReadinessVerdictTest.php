<?php

namespace Tests\Feature\Console\Commands;

use App\Models\PreBackfillSnapshotRun;
use App\Models\PreRunIntegrityCapture;
use App\Models\TaxBackfillMaterialityRecord;
use App\Models\TaxBackfillMaterialityRun;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes, Slice 20 (T076). See
 * specs/002-backfill-transaction-taxes/slice-20-readiness-verdict-brief.md.
 *
 * Covers `transactions:tax-backfill-readiness-verdict`: pure-reader
 * behavior (zero writes in every scenario), FAIL conditions (missing
 * pre/post-run capture, non-zero post-run null count, duplicates worse than
 * baseline, missing connection-identity evidence), WARN conditions
 * (materiality omitted/incomplete/mismatched, backup drill unconfirmed —
 * none of these ever escalate to FAIL), explicit-ID overrides, and the
 * overall pass/warn/fail rollup.
 *
 * Named with "Backfill" in the class for this feature's `--filter=Backfill`
 * convention. Every fixture uses explicit IDs (never "no rows exist at
 * all") to stay correct under the known tests/TestCase.php:38
 * RefreshDatabase leak between test methods.
 */
class BackfillTaxReadinessVerdictTest extends TestCase
{
    private const COMMAND = 'transactions:tax-backfill-readiness-verdict';

    private function makeCapture(string $phase, ?int $nullCount, int $dupGroups, int $dupRows): PreRunIntegrityCapture
    {
        return PreRunIntegrityCapture::create([
            'window_start' => '2032-01-01',
            'window_end' => '2032-02-01',
            'phase' => $phase,
            'duplicate_check_summary' => [
                'total_duplicate_groups' => $dupGroups,
                'total_duplicate_rows' => $dupRows,
                'sample' => [],
            ],
            'transaction_taxes_null_count' => $nullCount,
            'integrity_report' => 'stub report',
            'captured_at' => now(),
        ]);
    }

    private function makeConnectionIdentityEvidence(int $tenantId, string $businessDate): void
    {
        if (! Tenant::query()->where('id', $tenantId)->exists()) {
            Tenant::factory()->create(['id' => $tenantId]);
        }

        DB::table('report_refresh_states')->insert([
            'report_type' => 'daily_transaction_summaries',
            'tenant_id' => $tenantId,
            'business_date' => $businessDate,
            'status' => 'completed',
            'refreshed_at' => now(),
            'server_id' => 1,
            'database_name' => 'test_db',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeSnapshotRun(): PreBackfillSnapshotRun
    {
        return PreBackfillSnapshotRun::create([
            'snapshot_type' => PreBackfillSnapshotRun::TYPE_PRE_BACKFILL_RENDERED_AGGREGATE,
            'report_contract_version' => PreBackfillSnapshotRun::REPORT_CONTRACT_VERSION_CMSR_V2,
            'window_start' => '2032-01-01',
            'window_end' => '2032-02-01',
            'status' => PreBackfillSnapshotRun::STATUS_COMPLETED,
            'tenant_count' => 1,
            'month_count' => 1,
            'forced' => false,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    private function makeMaterialityRun(int $snapshotRunId, int $sourceMismatchCount = 0, int $comparedCount = 1, int $tenantIdBase = 900000): TaxBackfillMaterialityRun
    {
        $run = TaxBackfillMaterialityRun::create([
            'snapshot_run_id' => $snapshotRunId,
            'report_contract_version' => TaxBackfillMaterialityRun::REPORT_CONTRACT_VERSION_CMSR_V2,
            'status' => TaxBackfillMaterialityRun::STATUS_COMPLETED,
            'tenant_count' => 1,
            'month_count' => 1,
            'forced' => false,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $tenantId = $tenantIdBase;

        for ($i = 0; $i < $comparedCount; $i++) {
            TaxBackfillMaterialityRecord::create([
                'run_id' => $run->id,
                'tenant_id' => $tenantId + $i,
                'reporting_year' => 2032,
                'reporting_month' => 1,
                'before_source' => 'daily_transaction_summaries',
                'after_source' => 'daily_transaction_summaries',
                'after_rendered_result' => ['status' => 'success'],
                'comparison_status' => TaxBackfillMaterialityRecord::COMPARISON_STATUS_COMPARED,
                'other_tax_before' => 100.0,
                'other_tax_after' => 150.0,
                'other_tax_delta_amount' => 50.0,
                'other_tax_delta_percent' => 50.0,
                'captured_at' => now(),
            ]);
        }

        for ($i = 0; $i < $sourceMismatchCount; $i++) {
            TaxBackfillMaterialityRecord::create([
                'run_id' => $run->id,
                'tenant_id' => $tenantId + 1000 + $i,
                'reporting_year' => 2032,
                'reporting_month' => 1,
                'before_source' => 'raw_transactions',
                'after_source' => 'daily_transaction_summaries',
                'after_rendered_result' => ['status' => 'success'],
                'comparison_status' => TaxBackfillMaterialityRecord::COMPARISON_STATUS_SOURCE_MISMATCH,
                'other_tax_before' => null,
                'other_tax_after' => null,
                'other_tax_delta_amount' => null,
                'other_tax_delta_percent' => null,
                'captured_at' => now(),
            ]);
        }

        return $run;
    }

    private function decodeJson(string $output): array
    {
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "Command output was not valid JSON: {$output}");

        return $decoded;
    }

    private function baseArgs(): array
    {
        return [
            '--from' => '2032-01-01',
            '--to' => '2032-02-01',
            '--json' => true,
        ];
    }

    public function test_pass_when_all_evidence_is_clean_and_present(): void
    {
        $preRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_PRE_RUN, 3, 0, 0);
        $postRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_POST_RUN, 0, 0, 0);
        $this->makeConnectionIdentityEvidence(555001, '2032-01-15');
        $snapshotRun = $this->makeSnapshotRun();
        $this->makeMaterialityRun($snapshotRun->id, tenantIdBase: 555001);

        $exit = Artisan::call(self::COMMAND, array_merge($this->baseArgs(), [
            '--pre-run-capture-id' => $preRun->id,
            '--post-run-capture-id' => $postRun->id,
            '--snapshot-run' => $snapshotRun->id,
            '--tenant' => 555001,
            '--backup-drill-confirmed' => true,
        ]));
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame('pass', $result['overall_status']);
        foreach ($result['checks'] as $name => $check) {
            $this->assertSame('pass', $check['status'], "Expected check '{$name}' to pass: {$check['reason']}");
        }
    }

    public function test_fail_when_pre_run_capture_id_not_found_but_post_run_is_valid(): void
    {
        $postRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_POST_RUN, 0, 0, 0);

        $exit = Artisan::call(self::COMMAND, array_merge($this->baseArgs(), [
            '--pre-run-capture-id' => 999999999,
            '--post-run-capture-id' => $postRun->id,
        ]));
        $result = $this->decodeJson(Artisan::output());

        $this->assertNotEquals(0, $exit);
        $this->assertSame('fail', $result['overall_status']);
        $this->assertSame('fail', $result['checks']['pre_run_capture']['status']);
        $this->assertSame('pass', $result['checks']['post_run_capture']['status']);
    }

    public function test_fail_when_post_run_capture_id_not_found_but_pre_run_is_valid(): void
    {
        $preRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_PRE_RUN, 0, 0, 0);

        $exit = Artisan::call(self::COMMAND, array_merge($this->baseArgs(), [
            '--pre-run-capture-id' => $preRun->id,
            '--post-run-capture-id' => 999999998,
        ]));
        $result = $this->decodeJson(Artisan::output());

        $this->assertNotEquals(0, $exit);
        $this->assertSame('fail', $result['overall_status']);
        $this->assertSame('pass', $result['checks']['pre_run_capture']['status']);
        $this->assertSame('fail', $result['checks']['post_run_capture']['status']);
    }

    public function test_fail_when_explicit_capture_id_belongs_to_the_wrong_phase(): void
    {
        $preRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_PRE_RUN, 0, 0, 0);

        // Deliberately swapped: pre-run's id passed as the post-run option.
        $exit = Artisan::call(self::COMMAND, array_merge($this->baseArgs(), [
            '--pre-run-capture-id' => $preRun->id,
            '--post-run-capture-id' => $preRun->id,
        ]));
        $result = $this->decodeJson(Artisan::output());

        $this->assertNotEquals(0, $exit);
        $this->assertSame('fail', $result['checks']['post_run_capture']['status']);
        $this->assertStringContainsString('different phase', $result['checks']['post_run_capture']['reason']);
    }

    public function test_fail_when_post_run_null_count_is_nonzero(): void
    {
        $preRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_PRE_RUN, 0, 0, 0);
        $postRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_POST_RUN, 7, 0, 0);

        $exit = Artisan::call(self::COMMAND, array_merge($this->baseArgs(), [
            '--pre-run-capture-id' => $preRun->id,
            '--post-run-capture-id' => $postRun->id,
        ]));
        $result = $this->decodeJson(Artisan::output());

        $this->assertNotEquals(0, $exit);
        $this->assertSame('fail', $result['overall_status']);
        $this->assertSame('fail', $result['checks']['structural_check']['status']);
        $this->assertStringContainsString('not zero', $result['checks']['structural_check']['reason']);
    }

    public function test_fail_when_post_run_duplicates_exceed_pre_run_baseline(): void
    {
        $preRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_PRE_RUN, 0, 1, 2);
        $postRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_POST_RUN, 0, 3, 6);

        $exit = Artisan::call(self::COMMAND, array_merge($this->baseArgs(), [
            '--pre-run-capture-id' => $preRun->id,
            '--post-run-capture-id' => $postRun->id,
        ]));
        $result = $this->decodeJson(Artisan::output());

        $this->assertNotEquals(0, $exit);
        $this->assertSame('fail', $result['checks']['structural_check']['status']);
    }

    public function test_structural_check_does_not_fail_when_post_run_duplicates_merely_match_baseline(): void
    {
        $preRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_PRE_RUN, 0, 1, 2);
        $postRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_POST_RUN, 0, 1, 2);

        $exit = Artisan::call(self::COMMAND, array_merge($this->baseArgs(), [
            '--pre-run-capture-id' => $preRun->id,
            '--post-run-capture-id' => $postRun->id,
        ]));
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame('pass', $result['checks']['structural_check']['status']);
    }

    public function test_fail_when_no_connection_identity_evidence_exists_for_window(): void
    {
        $preRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_PRE_RUN, 0, 0, 0);
        $postRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_POST_RUN, 0, 0, 0);

        $exit = Artisan::call(self::COMMAND, array_merge($this->baseArgs(), [
            '--pre-run-capture-id' => $preRun->id,
            '--post-run-capture-id' => $postRun->id,
            '--tenant' => 777777, // a tenant guaranteed to have no report_refresh_states rows
        ]));
        $result = $this->decodeJson(Artisan::output());

        $this->assertNotEquals(0, $exit);
        $this->assertSame('fail', $result['checks']['connection_identity']['status']);
    }

    public function test_warn_not_fail_when_snapshot_run_omitted_and_backup_drill_not_confirmed(): void
    {
        $preRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_PRE_RUN, 0, 0, 0);
        $postRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_POST_RUN, 0, 0, 0);
        $this->makeConnectionIdentityEvidence(555002, '2032-01-16');

        $exit = Artisan::call(self::COMMAND, array_merge($this->baseArgs(), [
            '--pre-run-capture-id' => $preRun->id,
            '--post-run-capture-id' => $postRun->id,
            '--tenant' => 555002,
        ]));
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit, 'A WARN-only verdict must still exit 0 -- it is a human decision point, not a command failure.');
        $this->assertSame('warn', $result['overall_status']);
        $this->assertSame('warn', $result['checks']['materiality']['status']);
        $this->assertSame('warn', $result['checks']['backup_drill_attestation']['status']);
    }

    public function test_warn_when_materiality_run_has_source_mismatch_records(): void
    {
        $preRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_PRE_RUN, 0, 0, 0);
        $postRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_POST_RUN, 0, 0, 0);
        $this->makeConnectionIdentityEvidence(555003, '2032-01-17');
        $snapshotRun = $this->makeSnapshotRun();
        $this->makeMaterialityRun($snapshotRun->id, sourceMismatchCount: 1, comparedCount: 1);

        $exit = Artisan::call(self::COMMAND, array_merge($this->baseArgs(), [
            '--pre-run-capture-id' => $preRun->id,
            '--post-run-capture-id' => $postRun->id,
            '--snapshot-run' => $snapshotRun->id,
            // Deliberately no --tenant: this test is about the run-wide
            // source-mismatch signal, not per-tenant scoping (see the
            // dedicated --tenant materiality test below).
            '--backup-drill-confirmed' => true,
        ]));
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame('warn', $result['overall_status']);
        $this->assertSame('warn', $result['checks']['materiality']['status']);
        $this->assertSame(1, $result['checks']['materiality']['summary']['source_mismatch']);
        $this->assertSame(1, $result['checks']['materiality']['summary']['compared']);
    }

    public function test_materiality_check_is_scoped_by_tenant_when_tenant_option_given(): void
    {
        $preRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_PRE_RUN, 0, 0, 0);
        $postRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_POST_RUN, 0, 0, 0);
        $snapshotRun = $this->makeSnapshotRun();
        // makeMaterialityRun() seeds compared records starting at tenant_id
        // 900000 -- passing an unrelated tenant must find nothing for it.
        $this->makeMaterialityRun($snapshotRun->id, sourceMismatchCount: 0, comparedCount: 1);

        $exit = Artisan::call(self::COMMAND, array_merge($this->baseArgs(), [
            '--pre-run-capture-id' => $preRun->id,
            '--post-run-capture-id' => $postRun->id,
            '--snapshot-run' => $snapshotRun->id,
            '--tenant' => 123456, // not one of the seeded materiality tenant_ids
        ]));
        $result = $this->decodeJson(Artisan::output());

        $this->assertNotEquals(0, $exit); // connection_identity still fails for this unrelated tenant, unrelated to this assertion
        $this->assertSame('warn', $result['checks']['materiality']['status']);
        $this->assertStringContainsString('no records for tenant', $result['checks']['materiality']['reason']);
        $this->assertNull($result['checks']['materiality']['summary']);
    }

    public function test_materiality_run_override_must_belong_to_the_given_snapshot_run(): void
    {
        $preRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_PRE_RUN, 0, 0, 0);
        $postRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_POST_RUN, 0, 0, 0);
        $snapshotRunA = $this->makeSnapshotRun();
        $snapshotRunB = $this->makeSnapshotRun();
        $materialityUnderB = $this->makeMaterialityRun($snapshotRunB->id);

        Artisan::call(self::COMMAND, array_merge($this->baseArgs(), [
            '--pre-run-capture-id' => $preRun->id,
            '--post-run-capture-id' => $postRun->id,
            '--snapshot-run' => $snapshotRunA->id,
            '--materiality-run' => $materialityUnderB->id,
        ]));
        $result = $this->decodeJson(Artisan::output());

        // A materiality mismatch is a WARN, never a FAIL -- no exit-code
        // assertion here, matching the established "materiality can never
        // escalate the overall verdict" design.
        $this->assertSame('warn', $result['checks']['materiality']['status']);
        $this->assertStringContainsString('does not belong to snapshot run', $result['checks']['materiality']['reason']);
    }

    public function test_explicit_capture_and_materiality_run_overrides_are_honored(): void
    {
        // Two candidates of each phase; the explicit --*-id options must
        // pick the ones named, not "most recent."
        $olderPreRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_PRE_RUN, 0, 0, 0);
        $this->makeCapture(PreRunIntegrityCapture::PHASE_PRE_RUN, 99, 0, 0); // newer, would fail if picked by default

        $targetPostRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_POST_RUN, 0, 0, 0);
        $this->makeCapture(PreRunIntegrityCapture::PHASE_POST_RUN, 99, 0, 0);

        Artisan::call(self::COMMAND, array_merge($this->baseArgs(), [
            '--pre-run-capture-id' => $olderPreRun->id,
            '--post-run-capture-id' => $targetPostRun->id,
        ]));
        $result = $this->decodeJson(Artisan::output());

        // Only asserting the structural check here -- connection-identity/
        // materiality are irrelevant to this test's purpose (proving the
        // explicit capture-id overrides are honored over "most recent") and
        // are expected to fail/warn since no evidence was seeded for them.
        $this->assertSame('pass', $result['checks']['structural_check']['status']);
    }

    public function test_writes_nothing_in_any_scenario(): void
    {
        $tablesToWatch = [
            'pre_run_integrity_captures',
            'tax_backfill_materiality_runs',
            'tax_backfill_materiality_records',
            'pre_backfill_snapshot_runs',
            'report_refresh_states',
            'transaction_taxes',
            'transactions',
            'daily_transaction_summaries',
        ];

        $preRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_PRE_RUN, 0, 0, 0);
        $postRun = $this->makeCapture(PreRunIntegrityCapture::PHASE_POST_RUN, 0, 0, 0);
        $snapshotRun = $this->makeSnapshotRun();
        $this->makeMaterialityRun($snapshotRun->id);
        $this->makeConnectionIdentityEvidence(555004, '2032-01-18');

        $watermarks = [];
        foreach ($tablesToWatch as $table) {
            $watermarks[$table] = DB::table($table)->count();
        }

        Artisan::call(self::COMMAND, array_merge($this->baseArgs(), [
            '--pre-run-capture-id' => $preRun->id,
            '--post-run-capture-id' => $postRun->id,
            '--snapshot-run' => $snapshotRun->id,
            '--tenant' => 555004,
            '--backup-drill-confirmed' => true,
        ]));

        foreach ($tablesToWatch as $table) {
            $this->assertSame($watermarks[$table], DB::table($table)->count(), "Table {$table} row count changed -- this command must never write anything.");
        }
    }
}
